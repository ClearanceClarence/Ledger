<?php

class Auth
{
    private array $config;
    private string $lockFile;

    public function __construct(array $securityConfig)
    {
        $this->config = $securityConfig;
        // Keep lockout state inside logs/ (web-protected) rather than the
        // system temp dir, which is world-readable on shared hosts — another
        // tenant could read the failed-attempt IP list or clear the counters.
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
        $this->lockFile = $logDir . '/lockout.json';
    }

    // Session Management

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $lifetime = $this->config['session_lifetime'] ?? 3600;
        $secure = $this->isHttps();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite'  => 'Strict',
        ]);

        session_name($this->config['session_name'] ?? 'LEDGER_SESSION');
        session_start();

        // Session timeout check
        if (isset($_SESSION['ledger_last_activity'])) {
            if (time() - $_SESSION['ledger_last_activity'] > $lifetime) {
                $this->logout();
                return;
            }
        }
        $_SESSION['ledger_last_activity'] = time();

        // Regenerate session ID periodically (every 15 min)
        if (!isset($_SESSION['ledger_created'])) {
            $_SESSION['ledger_created'] = time();
        } elseif (time() - $_SESSION['ledger_created'] > 900) {
            session_regenerate_id(true);
            $_SESSION['ledger_created'] = time();
        }
    }

    // Authentication

    public function isAuthRequired(): bool
    {
        return !empty($this->config['require_auth']);
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['ledger_authenticated']);
    }

    public function getUsername(): string
    {
        return $_SESSION['ledger_username'] ?? '';
    }

    /**
     * Attempt login. Returns true on success, error string on failure,
     * or '2fa_required' if TOTP verification is needed.
     */
    public function login(string $username, string $password): string|bool
    {
        $ip = $this->getClientIp();

        // Check lockout
        if ($this->isLockedOut($ip)) {
            $remaining = $this->getLockoutRemaining($ip);
            return "Too many failed attempts. Try again in {$remaining} seconds.";
        }

        $users = $this->config['users'] ?? [];

        if (!isset($users[$username])) {
            $this->recordFailedAttempt($ip);
            return 'Invalid username or password.';
        }

        $storedPassword = $users[$username];
        // Support both plain hash string and array with hash + totp_secret
        $hash = is_array($storedPassword) ? ($storedPassword['password'] ?? '') : $storedPassword;
        $totpSecret = is_array($storedPassword) ? ($storedPassword['totp_secret'] ?? null) : null;

        $valid = false;
        $isBcrypt = str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$');
        if ($isBcrypt) {
            $valid = password_verify($password, $hash);
        } else {
            // Legacy / hand-edited plaintext credential. Still accepted so we
            // don't lock anyone out, but upgraded to bcrypt below so it never
            // persists as plaintext past the first successful login.
            $valid = hash_equals($hash, $password);
        }

        if (!$valid) {
            $this->recordFailedAttempt($ip);
            return 'Invalid username or password.';
        }

        // Auto-upgrade a legacy plaintext credential to bcrypt so it never
        // persists as plaintext. Best-effort: if config.php isn't writable we
        // just continue with the login rather than failing it.
        if (!$isBcrypt) {
            $this->upgradePasswordHash($username, $password, $totpSecret);
        }

        // If 2FA is enabled for this user, require TOTP step
        if ($totpSecret) {
            $this->clearFailedAttempts($ip);
            session_regenerate_id(true);
            $_SESSION['ledger_2fa_pending'] = true;
            $_SESSION['ledger_2fa_username'] = $username;
            return '2fa_required';
        }

        // No 2FA — complete login
        $this->completeLogin($username, $ip);
        return true;
    }

    /**
     * Verify a TOTP code for a pending 2FA login.
     * @return bool|string True on success, error message string on failure
     */
    public function verify2fa(string $code): string|bool
    {
        if (empty($_SESSION['ledger_2fa_pending']) || empty($_SESSION['ledger_2fa_username'])) {
            return 'No 2FA session pending.';
        }

        $ip = $this->getClientIp();
        if ($this->isLockedOut($ip)) {
            $remaining = $this->getLockoutRemaining($ip);
            return "Too many failed attempts. Try again in {$remaining} seconds.";
        }

        $username = $_SESSION['ledger_2fa_username'];
        $users = $this->config['users'] ?? [];

        if (!isset($users[$username])) {
            return 'User not found.';
        }

        $userData = $users[$username];
        $totpSecret = is_array($userData) ? ($userData['totp_secret'] ?? null) : null;

        if (!$totpSecret) {
            // 2FA not actually configured — complete login
            $this->completeLogin($username, $ip);
            return true;
        }

        require_once __DIR__ . '/TOTP.php';
        $matchedStep = LedgerTOTP::verifyAt($totpSecret, $code);
        if ($matchedStep === null) {
            $this->recordFailedAttempt($ip);
            return 'Invalid verification code.';
        }

        // Replay protection: refuse a code whose time-step was already used for
        // this user. Without this, a captured code is reusable for up to ~90s.
        if ($this->totpStepAlreadyUsed($username, $matchedStep)) {
            $this->recordFailedAttempt($ip);
            return 'That code was already used. Wait for your authenticator to show a new one.';
        }
        $this->recordTotpStep($username, $matchedStep);

        // 2FA verified
        unset($_SESSION['ledger_2fa_pending'], $_SESSION['ledger_2fa_username']);
        $this->completeLogin($username, $ip);
        return true;
    }

    public function is2faPending(): bool
    {
        return !empty($_SESSION['ledger_2fa_pending']);
    }

    private function completeLogin(string $username, string $ip): void
    {
        $this->clearFailedAttempts($ip);
        session_regenerate_id(true);
        $_SESSION['ledger_authenticated'] = true;
        $_SESSION['ledger_username'] = $username;
        $_SESSION['ledger_login_time'] = time();
        $_SESSION['ledger_ip'] = $ip;
        unset($_SESSION['ledger_2fa_pending'], $_SESSION['ledger_2fa_username']);
        $this->logActivity('Login successful', $username);
    }

    public function getUserTotpSecret(string $username): ?string
    {
        $users = $this->config['users'] ?? [];
        $userData = $users[$username] ?? null;
        if (is_array($userData)) {
            return $userData['totp_secret'] ?? null;
        }
        return null;
    }

    public function getUserPasswordHash(string $username): ?string
    {
        $users = $this->config['users'] ?? [];
        $userData = $users[$username] ?? null;
        if ($userData === null) return null;
        return is_array($userData) ? ($userData['password'] ?? null) : $userData;
    }

    public function logout(): void
    {
        $username = $this->getUsername();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();

        if ($username) {
            $this->logActivity('Logout', $username);
        }
    }

    // CSRF Protection

    public function csrfEnabled(): bool
    {
        return !empty($this->config['csrf_enabled']);
    }

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['ledger_csrf_token'])) {
            $_SESSION['ledger_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['ledger_csrf_token'];
    }

    public function csrfField(): string
    {
        $token = $this->generateCsrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    public function csrfMeta(): string
    {
        $token = $this->generateCsrfToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }

    /**
     * Checks $_POST['_csrf_token'] or X-CSRF-Token header against the session token.
     */
    public function validateCsrf(): bool
    {
        if (!$this->csrfEnabled()) return true;

        $token = $_POST['_csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        if (empty($token) || empty($_SESSION['ledger_csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['ledger_csrf_token'], $token);
    }

    // IP Whitelist

    public function isIpAllowed(): bool
    {
        $whitelist = $this->config['ip_whitelist'] ?? [];
        if (empty($whitelist)) return true;

        $clientIp = $this->getClientIp();

        foreach ($whitelist as $allowed) {
            if (str_contains($allowed, '/')) {
                // CIDR notation
                if ($this->ipInCidr($clientIp, $allowed)) return true;
            } else {
                if ($clientIp === $allowed) return true;
            }
        }

        return false;
    }

    // HTTPS Enforcement

    public function shouldForceHttps(): bool
    {
        return !empty($this->config['force_https']) && !$this->isHttps();
    }

    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }

    // Read-Only Mode

    public function isReadOnly(): bool
    {
        return !empty($this->config['read_only']);
    }

    /**
     * Detect INSERT/UPDATE/DELETE/ALTER/DROP/CREATE/TRUNCATE/RENAME/GRANT/REVOKE.
     */
    public function isWriteQuery(string $sql): bool
    {
        $writeKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
            'CREATE', 'RENAME', 'REPLACE', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK'];

        // Multi-statement support: any statement starting with a write keyword
        // counts. Split crudely on semicolons (this check tolerates some false
        // positives — e.g. a semicolon inside a string — but it errs toward
        // safer rejection rather than letting writes through.)
        $statements = preg_split('/;\s*/', $sql);
        foreach ($statements as $stmt) {
            // Strip leading SQL comments and whitespace so a comment prefix
            // like "/* x */ DELETE …" or "-- x\nDELETE …" can't hide a write.
            $stmt = preg_replace('#^(\s|/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n)+#s', '', $stmt);
            $trimmed = strtoupper(ltrim($stmt));
            if ($trimmed === '') continue;

            foreach ($writeKeywords as $kw) {
                if (str_starts_with($trimmed, $kw)) return true;
            }

            // A CTE (WITH …) can wrap a writing statement: "WITH x AS (…) DELETE …".
            // If any write keyword appears after the WITH, treat it as a write.
            if (str_starts_with($trimmed, 'WITH')) {
                foreach ($writeKeywords as $kw) {
                    if (preg_match('/\b' . $kw . '\b/', $trimmed)) return true;
                }
            }
        }
        return false;
    }

    // Hidden Databases

    /**
     * Remove hidden databases (mysql, information_schema, etc.) based on config.
     */
    public function filterDatabases(array $databases): array
    {
        $hidden = array_map('strtolower', $this->config['hidden_databases'] ?? []);
        if (empty($hidden)) return $databases;

        return array_values(array_filter($databases, function ($db) use ($hidden) {
            return !in_array(strtolower($db), $hidden);
        }));
    }

    public function isDatabaseHidden(string $db): bool
    {
        $hidden = array_map('strtolower', $this->config['hidden_databases'] ?? []);
        return in_array(strtolower($db), $hidden);
    }

    // Query Logging

    /**
     * Append to logs/queries.log. Silently fails if log dir is not writable.
     */
    public function logQuery(string $db, string $sql, float $time): void
    {
        if (empty($this->config['query_log'])) return;

        $logFile = $this->config['query_log_file'] ?? __DIR__ . '/../logs/queries.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);

        $entry = sprintf(
            "[%s] [%s] [%s] [%.4fs] %s\n",
            date('Y-m-d H:i:s'),
            $this->getUsername() ?: 'anonymous',
            $db ?: '-',
            $time,
            rtrim(preg_replace('/\s+/', ' ', $sql))
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public function logActivity(string $action, string $username = ''): void
    {
        if (empty($this->config['query_log'])) return;

        $logFile = $this->config['query_log_file'] ?? __DIR__ . '/../logs/queries.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);

        $entry = sprintf(
            "[%s] [%s] [%s] ACTION: %s\n",
            date('Y-m-d H:i:s'),
            $username ?: ($this->getUsername() ?: 'anonymous'),
            $this->getClientIp(),
            $action
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    // Brute Force Protection

    private function getAttempts(): array
    {
        if (!file_exists($this->lockFile)) return [];
        $data = @json_decode(file_get_contents($this->lockFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveAttempts(array $data): void
    {
        @file_put_contents($this->lockFile, json_encode($data), LOCK_EX);
    }

    private function recordFailedAttempt(string $ip): void
    {
        $data = $this->getAttempts();
        if (!isset($data[$ip])) {
            $data[$ip] = ['count' => 0, 'first' => time()];
        }
        $data[$ip]['count']++;
        $data[$ip]['last'] = time();
        $this->saveAttempts($data);
    }

    private function clearFailedAttempts(string $ip): void
    {
        $data = $this->getAttempts();
        unset($data[$ip]);
        $this->saveAttempts($data);
    }

    /**
     * Check if an IP has exceeded max_attempts within lockout_time seconds.
     */
    public function isLockedOut(string $ip): bool
    {
        $data = $this->getAttempts();
        if (!isset($data[$ip])) return false;

        $maxAttempts = $this->config['max_login_attempts'] ?? 5;
        $lockoutDuration = $this->config['lockout_duration'] ?? 300;

        if ($data[$ip]['count'] >= $maxAttempts) {
            $elapsed = time() - ($data[$ip]['last'] ?? 0);
            if ($elapsed < $lockoutDuration) {
                return true;
            }
            // Lockout expired, clear
            $this->clearFailedAttempts($ip);
            return false;
        }

        return false;
    }

    public function getLockoutRemaining(string $ip): int
    {
        $data = $this->getAttempts();
        if (!isset($data[$ip])) return 0;
        $lockoutDuration = $this->config['lockout_duration'] ?? 300;
        $elapsed = time() - ($data[$ip]['last'] ?? 0);
        return max(0, $lockoutDuration - $elapsed);
    }

    // TOTP Replay Protection

    private function totpFile(): string
    {
        return __DIR__ . '/../logs/totp_used.json';
    }

    private function totpStepAlreadyUsed(string $username, int $step): bool
    {
        $file = $this->totpFile();
        if (!file_exists($file)) return false;
        $data = @json_decode(@file_get_contents($file), true);
        if (!is_array($data)) return false;
        return (int)($data[$username] ?? -1) === $step;
    }

    private function recordTotpStep(string $username, int $step): void
    {
        $file = $this->totpFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $data = [];
        if (file_exists($file)) {
            $decoded = @json_decode(@file_get_contents($file), true);
            if (is_array($decoded)) $data = $decoded;
        }
        $data[$username] = $step;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Rewrite a user's stored credential as a bcrypt hash, preserving any
     * configured TOTP secret. Best-effort — silently no-ops if config.php
     * can't be loaded or written.
     */
    private function upgradePasswordHash(string $username, string $plaintext, ?string $totpSecret): void
    {
        $configPath = __DIR__ . '/../config.php';
        if (!is_file($configPath) || !is_writable($configPath)) return;

        $config = require $configPath;
        if (!is_array($config) || !isset($config['security']['users'][$username])) return;

        $newHash = password_hash($plaintext, PASSWORD_BCRYPT);
        $config['security']['users'][$username] = $totpSecret
            ? ['password' => $newHash, 'totp_secret' => $totpSecret]
            : $newHash;

        require_once __DIR__ . '/../templates/settings_save.php';
        if (function_exists('ledger_write_config_file')) {
            @ledger_write_config_file($configPath, $config);
        }
    }

    // Helpers

    public function getClientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Forwarded headers are attacker-controlled unless the request comes
        // from a proxy we explicitly trust. Without that gate, anyone can send
        // X-Forwarded-For to bypass the IP allowlist or rotate it to defeat
        // brute-force lockout. Only consult them when REMOTE_ADDR is a known
        // trusted proxy, and take the left-most (original client) entry.
        $trusted = $this->config['trusted_proxies'] ?? [];
        if (!empty($trusted) && in_array($remote, $trusted, true)) {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return $remote;
    }

    public function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $subnet = ip2long($subnet);
        $ip = ip2long($ip);
        if ($subnet === false || $ip === false) return false;
        $mask = -1 << (32 - (int)$bits);
        return ($ip & $mask) === ($subnet & $mask);
    }
}
