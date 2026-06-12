<?php
/**
 * Ledger — Settings Save Helper
 *
 * Extracted from settings.php so it can be called BEFORE the layout
 * renders. This lets us header('Location: …') after saving, ensuring
 * the new config (sidebar filters, auth rules, etc.) is applied
 * immediately on the next request instead of requiring a manual reload.
 */

/**
 * Write config array back to PHP file
 */
function ledger_write_config_file(string $path, array $config): bool
{
    $render = function ($val, int $indent = 0) use (&$render) {
        $pad = str_repeat('    ', $indent);
        if (is_array($val)) {
            if (empty($val)) return '[]';
            $isSeq = array_keys($val) === range(0, count($val) - 1);
            $lines = [];
            foreach ($val as $k => $v) {
                $key = $isSeq ? '' : var_export($k, true) . ' => ';
                $lines[] = $pad . '    ' . $key . $render($v, $indent + 1);
            }
            return "[\n" . implode(",\n", $lines) . ",\n" . $pad . ']';
        }
        return var_export($val, true);
    };

    $out = "<?php\nreturn " . $render($config) . ";\n";

    // Preserve __DIR__ for log file paths if the user left it as default
    $out = str_replace(
        "'__DIR__ . '/logs/queries.log''",
        "__DIR__ . '/logs/queries.log'",
        $out
    );

    return (bool)@file_put_contents($path, $out, LOCK_EX);
}

/**
 * Process a settings POST and save config.php.
 *
 * Returns an array:
 *   [
 *     'success' => bool,
 *     'config'  => array,   // the new config (always returned, even on error)
 *     'error'   => string,  // only set on failure
 *   ]
 */
function ledger_save_settings(array $config, $auth): array
{
    // CSRF check
    if ($auth && method_exists($auth, 'csrfEnabled') && $auth->csrfEnabled() && !$auth->validateCsrf()) {
        return ['success' => false, 'config' => $config, 'error' => 'Invalid security token. Please reload and try again.'];
    }

    $newConfig = $config;

    // Database
    $newConfig['db']['host']     = trim($_POST['db_host'] ?? '127.0.0.1');
    $newConfig['db']['port']     = (int)($_POST['db_port'] ?? 3306);
    $newConfig['db']['username'] = trim($_POST['db_user'] ?? 'root');
    if (($_POST['db_pass_changed'] ?? '0') === '1') {
        $newConfig['db']['password'] = $_POST['db_pass'] ?? '';
    }

    // App
    $newConfig['app']['default_theme']   = $_POST['default_theme'] ?? 'light-clean';
    $newConfig['app']['rows_per_page']   = max(10, min(500, (int)($_POST['rows_per_page'] ?? 50)));
    $newConfig['app']['enable_export']   = isset($_POST['enable_export']);
    $newConfig['app']['version_check']   = isset($_POST['version_check']);

    // Fonts
    $fontZones = function_exists('ledger_font_zones') ? ledger_font_zones() : [];
    if (!isset($newConfig['app']['fonts'])) $newConfig['app']['fonts'] = [];
    foreach ($fontZones as $zoneKey => $zone) {
        $newConfig['app']['fonts'][$zoneKey] = trim($_POST['font_' . $zoneKey] ?? '');
    }

    // Security
    $newConfig['security']['require_auth']       = isset($_POST['require_auth']);
    $newConfig['security']['csrf_enabled']       = isset($_POST['csrf_enabled']);

    // force_https lockout guard: enabling HTTPS enforcement over a plain
    // HTTP connection guarantees a lockout if HTTPS isn't actually served
    // on this host (e.g. default XAMPP has no 443 listener — every request
    // would redirect to a connection that can't be made). Only allow
    // turning it ON when the current request is already HTTPS, which
    // proves HTTPS works. Turning it OFF is always allowed.
    $wantsForceHttps = isset($_POST['force_https']);
    if ($wantsForceHttps && empty($config['security']['force_https']) && !$auth->isHttps()) {
        return [
            'success' => false,
            'error'   => 'Cannot enable "Force HTTPS" over a plain HTTP connection — that would lock you out if HTTPS isn\'t working on this server. Open Ledger via https:// first, then enable it.',
            'config'  => $newConfig,
        ];
    }
    $newConfig['security']['force_https']        = $wantsForceHttps;

    $newConfig['security']['read_only']          = isset($_POST['read_only']);
    $newConfig['security']['session_lifetime']   = max(300, (int)($_POST['session_lifetime'] ?? 3600));
    $newConfig['security']['max_login_attempts'] = max(1, (int)($_POST['max_login_attempts'] ?? 5));
    $newConfig['security']['lockout_duration']   = max(30, (int)($_POST['lockout_duration'] ?? 300));
    $newConfig['security']['query_log']          = isset($_POST['query_log']);

    // IP whitelist
    $ipRaw = trim($_POST['ip_whitelist'] ?? '');
    $newConfig['security']['ip_whitelist'] = $ipRaw
        ? array_values(array_filter(array_map('trim', explode("\n", $ipRaw))))
        : [];

    // Trusted proxies — only these source IPs are allowed to set the client
    // IP via X-Forwarded-For / X-Real-IP. Empty = forwarded headers ignored.
    $proxyRaw = trim($_POST['trusted_proxies'] ?? '');
    $newConfig['security']['trusted_proxies'] = $proxyRaw
        ? array_values(array_filter(array_map('trim', explode("\n", $proxyRaw))))
        : [];

    // Hidden databases
    $hiddenRaw = trim($_POST['hidden_databases'] ?? '');
    $newConfig['security']['hidden_databases'] = $hiddenRaw
        ? array_values(array_filter(array_map('trim', explode("\n", $hiddenRaw))))
        : [];

    // Users
    $existingUsers = $newConfig['security']['users'] ?? [];
    $updatedUsers = [];
    $keepUsers = $_POST['keep_user'] ?? [];

    foreach ($existingUsers as $uname => $uhash) {
        if (in_array($uname, $keepUsers)) {
            $newPass = $_POST['user_newpass_' . $uname] ?? '';
            if (!empty($newPass)) {
                if (strlen($newPass) < 6) {
                    return ['success' => false, 'config' => $newConfig, 'error' => "Password for '{$uname}' must be at least 6 characters."];
                }
                $updatedUsers[$uname] = password_hash($newPass, PASSWORD_BCRYPT);
            } else {
                $updatedUsers[$uname] = $uhash;
            }
        }
    }

    // New user
    $newUsername = trim($_POST['new_username'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    if (!empty($newUsername)) {
        if (strlen($newUsername) < 3) {
            return ['success' => false, 'config' => $newConfig, 'error' => 'New username must be at least 3 characters.'];
        }
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'config' => $newConfig, 'error' => 'New password must be at least 6 characters.'];
        }
        if (isset($updatedUsers[$newUsername])) {
            return ['success' => false, 'config' => $newConfig, 'error' => "Username '{$newUsername}' already exists."];
        }
        $updatedUsers[$newUsername] = password_hash($newPassword, PASSWORD_BCRYPT);
    }

    if ($newConfig['security']['require_auth'] && empty($updatedUsers)) {
        return ['success' => false, 'config' => $newConfig, 'error' => 'You must have at least one user when authentication is enabled.'];
    }

    $newConfig['security']['users'] = $updatedUsers;

    // Write
    $written = ledger_write_config_file(__DIR__ . '/../config.php', $newConfig);
    if (!$written) {
        return ['success' => false, 'config' => $newConfig, 'error' => 'Could not write config.php. Check file permissions.'];
    }

    if ($auth && method_exists($auth, 'logActivity')) {
        $auth->logActivity('Settings updated');
    }

    return ['success' => true, 'config' => $newConfig];
}
