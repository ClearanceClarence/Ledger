<?php
/**
 * Ledger — First-Run Installer
 * Creates config.php with database connection and admin credentials.
 * This file is only accessible when config.php does not exist.
 */

// Block access if already installed
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit;
}

// Helpers
function ih(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);

// Step advancement is only valid via POST submission. Direct URL access to
// ?step=2 or ?step=3 (back button, manual URL editing, bookmarks) falls
// back to step 1 so users always start from a known state.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $step !== 1) {
    $step = 1;
}
$errors = [];
$values = [
    'db_host'       => $_POST['db_host'] ?? '127.0.0.1',
    'db_port'       => $_POST['db_port'] ?? '3306',
    'db_user'       => $_POST['db_user'] ?? 'root',
    'db_pass'       => $_POST['db_pass'] ?? '',
    'admin_user'    => $_POST['admin_user'] ?? '',
    'admin_pass'    => $_POST['admin_pass'] ?? '',
    'admin_pass2'   => $_POST['admin_pass2'] ?? '',
    // Checkboxes: present in POST = checked. On step 3 submit, absence means unchecked.
    // Default to checked on first render only ($_SERVER['REQUEST_METHOD'] !== 'POST').
    'hide_system'   => $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? (isset($_POST['hide_system']) ? '1' : '0')
                        : '1',
    'force_https'   => $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? (isset($_POST['force_https']) ? '1' : '0')
                        : '0',
];

// Step 2: Validate DB connection
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($values['db_host'])) $errors[] = 'Database host is required.';
    if (empty($values['db_port'])) $errors[] = 'Database port is required.';
    if (empty($values['db_user'])) $errors[] = 'Database username is required.';

    if (empty($errors)) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], (int)$values['db_port']);
            $pdo = new PDO($dsn, $values['db_user'], $values['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $dbVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $dbCount = $pdo->query('SHOW DATABASES')->rowCount();
        } catch (PDOException $e) {
            // Translate raw PDO errors into actionable messages
            $errors[] = friendlyDbError($e, $values);
            $step = 1; // Stay on step 1
        }
    } else {
        $step = 1;
    }
}

/**
 * Translate PDO connection errors into actionable, user-friendly messages.
 * Falls back to the raw message only if no specific case matches.
 * Plain text only — caller escapes for HTML output.
 */
function friendlyDbError(PDOException $e, array $values): string {
    $msg = $e->getMessage();
    $host = $values['db_host'] ?? '';
    $port = $values['db_port'] ?? '';
    $user = $values['db_user'] ?? '';

    // Wrong password / wrong username (MySQL access denied)
    if (str_contains($msg, '1045') || str_contains($msg, 'Access denied for user')) {
        $hint = str_contains($msg, 'using password: NO')
            ? ' (the password field was empty — most MySQL users require one)'
            : '';
        return "MySQL refused the connection for user \"{$user}\". The username or password is wrong{$hint}.";
    }

    // Host doesn't exist / DNS failure
    if (str_contains($msg, '2002') || str_contains($msg, "Can't connect") || str_contains($msg, 'getaddrinfo')) {
        return "Couldn't reach the MySQL server at {$host}:{$port}. Is the server running, and is the host/port correct? Try 127.0.0.1 instead of localhost on Linux if you're not sure.";
    }

    // Connection timeout
    if (str_contains($msg, '2003') || str_contains($msg, 'timed out')) {
        return "Connection to {$host}:{$port} timed out after 5 seconds. The server may be down, behind a firewall, or on a different port.";
    }

    // Connection refused (server present but not listening)
    if (str_contains($msg, 'Connection refused')) {
        return "MySQL on {$host}:{$port} refused the connection. Check that MySQL is running and accepting connections on that port.";
    }

    // Host not allowed to connect (MySQL's bind-address or user@host whitelist)
    if (str_contains($msg, '1130') || str_contains($msg, 'is not allowed to connect')) {
        return "MySQL is not configured to accept connections from this host. Check your MySQL bind-address setting and the user's allowed hosts (e.g. {$user}@% grants).";
    }

    // SSL / TLS issues
    if (str_contains($msg, 'SSL') || str_contains($msg, 'TLS')) {
        return "TLS/SSL handshake with MySQL failed. If your server requires SSL connections, the PHP pdo_mysql client needs to be configured to provide a certificate.";
    }

    // Fallback: show the raw message but framed as MySQL's error, not ours
    return "MySQL refused the connection. The server reported: {$msg}";
}

/**
 * Check if a password is on the list of most-commonly-leaked passwords.
 * Sourced from public breach analyses (HIBP, SecLists). Comparison is
 * case-insensitive — these passwords get cracked instantly regardless of case.
 */
function passwordIsCommon(string $pass): bool {
    static $common = [
        // Top 30 globally — these account for ~10% of cracked passwords
        '123456', '123456789', 'password', 'qwerty', '12345678', '111111',
        '12345', 'qwerty123', '1q2w3e4r', 'admin', 'letmein', 'welcome',
        'monkey', '1234567890', 'abc123', '123123', 'password1', 'iloveyou',
        '1234567', 'sunshine', 'master', '654321', '666666', 'princess',
        'dragon', 'football', 'baseball', 'superman', 'trustno1', 'qwertyuiop',
        // Common variations and predictable patterns
        'password123', 'admin123', 'root', 'toor', 'pass', 'passw0rd',
        'p@ssw0rd', 'p@ssword', 'qwerty1', 'qwerty12', '1qaz2wsx', 'zxcvbnm',
        'asdfgh', 'asdfghjkl', '11111111', '00000000', '12121212', '99999999',
        // Service / app names (very common on developer tools)
        'ledger', 'mysql', 'database', 'admin1', 'administrator', 'changeme',
        'default', 'guest', 'test', 'demo', 'temp', 'login', 'user',
        // Sports & culture
        'liverpool', 'arsenal', 'chelsea', 'barcelona', 'realmadrid',
        'starwars', 'pokemon', 'batman', 'spiderman',
        // Years that are commonly used
        '20202020', '20212021', '20222022', '20232023', '20242024', '20252025',
        // Keyboard walks
        'qazwsx', '147258369', '123qwe', 'qwe123', '321cba',
        // "Welcome"-type
        'welcome1', 'welcome123', 'hello123', 'login123',
    ];
    return in_array(strtolower($pass), $common, true);
}

// Step 3: Validate admin + write config
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Username rules
    if (empty($values['admin_user'])) {
        $errors[] = 'Admin username is required.';
    } elseif (strlen($values['admin_user']) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    } elseif (strlen($values['admin_user']) > 64) {
        $errors[] = 'Username must be 64 characters or fewer.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $values['admin_user'])) {
        $errors[] = 'Username can only contain letters, numbers, underscore, dot, and hyphen.';
    }

    // Password rules — bcrypt truncates at 72 chars, so we enforce that as the
    // hard maximum. Anything longer would silently lose data.
    $pass = $values['admin_pass'];
    if (empty($pass)) {
        $errors[] = 'Admin password is required.';
    } else {
        if (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (strlen($pass) > 72) {
            $errors[] = 'Password must be 72 characters or fewer (bcrypt limit).';
        }
        if ($values['admin_pass'] !== $values['admin_pass2']) {
            $errors[] = 'Passwords do not match.';
        }
        if (passwordIsCommon($pass)) {
            $errors[] = 'That password is on the list of most-leaked passwords. Choose something less common.';
        }
        if (!empty($values['admin_user']) && strtolower($pass) === strtolower($values['admin_user'])) {
            $errors[] = 'Password cannot be the same as the username.';
        }
    }

    if (empty($errors)) {
        // Build config
        $hash = password_hash($values['admin_pass'], PASSWORD_BCRYPT);
        $hiddenDbs = $values['hide_system'] === '1'
            ? "['information_schema', 'performance_schema', 'mysql', 'sys', 'phpmyadmin']"
            : '[]';

        $template = file_get_contents(__DIR__ . '/config.template.php');
        $config = str_replace(
            ['{{DB_HOST}}', '{{DB_PORT}}', '{{DB_USER}}', '{{DB_PASS}}',
             '{{ADMIN_USER}}', '{{ADMIN_HASH}}', '{{HIDDEN_DBS}}', '{{INSTALL_DATE}}'],
            [addslashes($values['db_host']), (int)$values['db_port'],
             addslashes($values['db_user']), addslashes($values['db_pass']),
             addslashes($values['admin_user']), $hash, $hiddenDbs, date('Y-m-d H:i:s')],
            $template
        );

        // Write config
        $written = @file_put_contents(__DIR__ . '/config.php', $config);
        if ($written === false) {
            $errors[] = 'Could not write config.php. Check that the ledger/ directory is writable by the web server.';
            $step = 2;
        }

        // Create logs directory
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0750, true);
        }
    } else {
        $step = 2;
    }
}

// Load theme for styling
$themeCss = 'themes/dark-industrial/style.css';
$themeOverride = '';
$cookieTheme = $_COOKIE['ledger_theme'] ?? '';
if ($cookieTheme && is_dir(__DIR__ . '/themes/' . basename($cookieTheme))) {
    $themeOverride = 'themes/' . ih(basename($cookieTheme)) . '/style.css';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/favicon-dark.svg" type="image/svg+xml" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="assets/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/favicon-180.png">
    <title>Ledger — Setup</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $themeCss ?>">
    <?php if ($themeOverride && $themeOverride !== $themeCss): ?>
    <link rel="stylesheet" href="<?= $themeOverride ?>">
    <?php endif; ?>
    <style>
        .installer-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-root);
            padding: 20px;
        }
        .installer-card {
            width: 100%;
            max-width: 520px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .installer-header {
            padding: 24px 28px 16px;
            border-bottom: 1px solid var(--border);
        }
        .installer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        .installer-logo svg { color: var(--accent); }
        .installer-logo-text {
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 20px;
            color: var(--accent);
        }
        .installer-subtitle {
            font-size: var(--font-size-sm);
            color: var(--text-muted);
        }
        .installer-steps {
            display: flex;
            gap: 4px;
            margin-top: 14px;
        }
        .step-dot {
            height: 4px;
            flex: 1;
            border-radius: 2px;
            background: var(--border);
            transition: background 0.3s;
        }
        .step-dot.active { background: var(--accent); }
        .step-dot.done { background: var(--accent); opacity: 0.5; }

        .installer-body { padding: 24px 28px; }

        .field { margin-bottom: 16px; }
        .field-row { display: flex; gap: 12px; }
        .field-row .field { flex: 1; }
        .field-label {
            display: block;
            font-size: var(--font-size-xs);
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .field-input {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: var(--font-size-base);
            outline: none;
            transition: border-color 0.15s;
            box-sizing: border-box;
        }
        .field-input:focus { border-color: var(--accent); }
        .field-hint {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            margin-top: 4px;
        }
        .field-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            cursor: pointer;
        }
        .field-check input { margin: 0; cursor: pointer; }

        .error-list {
            padding: 10px 14px;
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-size: var(--font-size-sm);
            color: var(--danger);
        }
        .error-list ul { margin: 4px 0 0 16px; padding: 0; }

        .success-card {
            text-align: center;
            padding: 20px 0;
        }
        .success-icon { color: var(--accent); margin-bottom: 12px; }
        .success-title {
            font-size: var(--font-size-xl);
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .success-text {
            color: var(--text-secondary);
            font-size: var(--font-size-sm);
            margin-bottom: 20px;
        }

        .db-ok {
            padding: 10px 14px;
            background: var(--accent-bg);
            border: 1px solid var(--accent-dim);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-family: var(--font-mono);
            font-size: var(--font-size-sm);
            color: var(--accent);
        }

        .installer-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 28px;
            border-top: 1px solid var(--border);
        }

        .btn-install {
            padding: 10px 24px;
            background: var(--accent);
            color: var(--text-inverse, #fff);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: var(--font-size-base);
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-install:hover { opacity: 0.9; }

        .btn-back {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            font-family: var(--font-body);
            font-size: var(--font-size-sm);
            cursor: pointer;
        }
        .btn-back:hover { background: var(--bg-hover); }

        .step-label {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
        }

        /* ─── Password field row: label + Generate button ──────────────── */
        .field-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .field-label-row .field-label {
            margin-bottom: 0;
        }
        .pw-generate {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font: inherit;
            font-size: var(--font-size-xs);
            font-weight: 500;
            cursor: pointer;
            transition: all 150ms ease;
        }
        .pw-generate:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-bg);
        }
        .pw-generate:active {
            transform: scale(0.97);
        }
        .pw-generate.is-copied {
            border-color: var(--accent);
            color: var(--accent);
            background: var(--accent-bg);
        }
        .pw-generate.is-copied svg {
            opacity: 0;
        }
        .pw-generate.is-copied::before {
            content: '✓ ';
        }

        /* ─── Password input with reveal-eye button ────────────────────── */
        .pw-wrap {
            position: relative;
        }
        .pw-wrap .field-input {
            padding-right: 38px; /* room for the eye button */
        }
        .pw-reveal {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 150ms ease, background 150ms ease;
        }
        .pw-reveal:hover {
            color: var(--text-primary);
            background: var(--bg-hover);
        }
        .pw-reveal:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: -1px;
        }

        /* ─── Password strength meter ──────────────────────────────────── */
        .pw-meter {
            margin: 8px 0 4px;
        }
        .pw-meter-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            height: 4px;
            margin-bottom: 6px;
        }
        .pw-meter-bar > span {
            background: var(--border);
            border-radius: 2px;
            transition: background-color 180ms ease;
        }
        .pw-meter-label {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            min-height: 1.2em;
            letter-spacing: 0.02em;
        }
        /* Strength levels — color the first N bars and set the label color */
        .pw-meter[data-score="0"] .pw-meter-label { color: var(--text-muted); }
        .pw-meter[data-score="1"] .pw-meter-bar > span:nth-child(-n+1),
        .pw-meter[data-score="2"] .pw-meter-bar > span:nth-child(-n+2) { background: #e54848; }
        .pw-meter[data-score="1"] .pw-meter-label,
        .pw-meter[data-score="2"] .pw-meter-label { color: #e54848; }

        .pw-meter[data-score="3"] .pw-meter-bar > span:nth-child(-n+3) { background: #f0a030; }
        .pw-meter[data-score="3"] .pw-meter-label { color: #f0a030; }

        .pw-meter[data-score="4"] .pw-meter-bar > span:nth-child(-n+4) { background: #88c850; }
        .pw-meter[data-score="4"] .pw-meter-label { color: #88c850; }

        .pw-meter[data-score="5"] .pw-meter-bar > span { background: var(--accent); }
        .pw-meter[data-score="5"] .pw-meter-label { color: var(--accent); }

        /* ─── Password rule checklist ──────────────────────────────────── */
        .pw-rules {
            list-style: none;
            margin: 8px 0 0;
            padding: 10px 12px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: var(--font-size-xs);
            color: var(--text-muted);
        }
        .pw-rules li {
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.7;
            transition: color 180ms ease;
        }
        .pw-rule-mark {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1px solid var(--border-light);
            border-radius: 50%;
            flex-shrink: 0;
            position: relative;
            transition: all 180ms ease;
        }
        .pw-rules li.is-met {
            color: var(--accent);
        }
        .pw-rules li.is-met .pw-rule-mark {
            background: var(--accent);
            border-color: var(--accent);
        }
        .pw-rules li.is-met .pw-rule-mark::after {
            content: '';
            position: absolute;
            left: 3px;
            top: 1px;
            width: 3px;
            height: 6px;
            border: solid var(--text-inverse, #0c0c12);
            border-width: 0 1.5px 1.5px 0;
            transform: rotate(45deg);
        }

        /* Confirmation match indicator */
        #pw-match.is-mismatch { color: #e54848; }
        #pw-match.is-match    { color: var(--accent); }
    </style>
</head>
<body>
<div class="installer-wrap">
    <div class="installer-card">
        <!-- Header -->
        <div class="installer-header">
            <div class="installer-logo">
                <svg width="28" height="28" viewBox="0 0 64 64" fill="none" style="color:var(--accent);">
                    <path d="M14 8H6v48h8" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M50 8h8v48h-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="18" y="22" width="28" height="4.5" rx="1.25" fill="currentColor"/>
                    <rect x="18" y="32" width="22" height="4.5" rx="1.25" fill="currentColor" opacity="0.7"/>
                    <rect x="18" y="42" width="28" height="4.5" rx="1.25" fill="currentColor" opacity="0.45"/>
                </svg>
                <span class="installer-logo-text">Ledger Setup</span>
            </div>
            <div class="installer-subtitle">
                <?php if ($step === 1): ?>Configure your database connection
                <?php elseif ($step === 2): ?>Create your admin account
                <?php elseif ($step === 3): ?>Installation complete
                <?php endif; ?>
            </div>
            <div class="installer-steps">
                <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
                <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></div>
                <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>"></div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div style="padding:16px 28px 0;">
            <div class="error-list">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                    <li><?= ih($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ Step 1: Database Connection ═══ -->
        <?php if ($step === 1): ?>
        <form method="post" action="?step=2">
            <input type="hidden" name="step" value="2">
            <div class="installer-body">
                <div class="field-row">
                    <div class="field" style="flex:3;">
                        <label class="field-label" for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" class="field-input"
                               value="<?= ih($values['db_host']) ?>" placeholder="127.0.0.1" required>
                    </div>
                    <div class="field" style="flex:1;">
                        <label class="field-label" for="db_port">Port</label>
                        <input type="number" id="db_port" name="db_port" class="field-input"
                               value="<?= ih($values['db_port']) ?>" placeholder="3306" required>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="db_user">Database Username</label>
                    <input type="text" id="db_user" name="db_user" class="field-input"
                           value="<?= ih($values['db_user']) ?>" placeholder="root" autocomplete="off" required>
                </div>
                <div class="field">
                    <label class="field-label" for="db_pass">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass" class="field-input"
                           value="<?= ih($values['db_pass']) ?>" placeholder="Leave empty for XAMPP default" autocomplete="off">
                    <div class="field-hint">XAMPP default: empty. WAMP default: empty. MAMP default: root</div>
                </div>
            </div>
            <div class="installer-footer">
                <span class="step-label">Step 1 of 3 — Database</span>
                <button type="submit" class="btn-install">Test Connection →</button>
            </div>
        </form>

        <!-- ═══ Step 2: Admin Account ═══ -->
        <?php elseif ($step === 2): ?>
        <form method="post" action="?step=3">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="db_host" value="<?= ih($values['db_host']) ?>">
            <input type="hidden" name="db_port" value="<?= ih($values['db_port']) ?>">
            <input type="hidden" name="db_user" value="<?= ih($values['db_user']) ?>">
            <input type="hidden" name="db_pass" value="<?= ih($values['db_pass']) ?>">
            <div class="installer-body">
                <?php if (isset($dbVersion)): ?>
                <div class="db-ok">
                    ✓ Connected to MySQL <?= ih($dbVersion) ?> — <?= $dbCount ?> databases found
                </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="admin_user">Admin Username</label>
                    <input type="text" id="admin_user" name="admin_user" class="field-input"
                           value="<?= ih($values['admin_user']) ?>" placeholder="admin" autocomplete="off"
                           minlength="3" maxlength="64" pattern="[a-zA-Z0-9_.\-]+" required autofocus>
                    <div class="field-hint">3–64 characters. Letters, numbers, dot, dash, underscore.</div>
                </div>
                <div class="field">
                    <div class="field-label-row">
                        <label class="field-label" for="admin_pass">Password</label>
                        <button type="button" class="pw-generate" id="pw-generate" title="Generate a strong password and copy it">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15A9 9 0 1 1 19.93 7l3.07 3"/></svg>
                            <span>Generate</span>
                        </button>
                    </div>
                    <div class="pw-wrap">
                        <input type="password" id="admin_pass" name="admin_pass" class="field-input"
                               placeholder="••••••••" autocomplete="new-password"
                               minlength="8" maxlength="72" required
                               aria-describedby="pw-meter pw-rules">
                        <button type="button" class="pw-reveal" data-target="admin_pass"
                                aria-label="Show password" aria-pressed="false">
                            <svg class="pw-eye-show" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-hide" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>

                    <!-- Live strength meter — 5 segments, color shifts from red → green -->
                    <div class="pw-meter" id="pw-meter" aria-hidden="true">
                        <div class="pw-meter-bar"><span></span><span></span><span></span><span></span><span></span></div>
                        <div class="pw-meter-label">Enter a password</div>
                    </div>

                    <!-- Visible rule checklist — items check off as the password satisfies them -->
                    <ul class="pw-rules" id="pw-rules">
                        <li data-rule="length"><span class="pw-rule-mark"></span>At least 8 characters</li>
                        <li data-rule="max"><span class="pw-rule-mark"></span>No more than 72 characters</li>
                        <li data-rule="notcommon"><span class="pw-rule-mark"></span>Not a commonly-leaked password</li>
                        <li data-rule="notuser"><span class="pw-rule-mark"></span>Different from the username</li>
                    </ul>
                </div>
                <div class="field">
                    <label class="field-label" for="admin_pass2">Confirm Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="admin_pass2" name="admin_pass2" class="field-input"
                               placeholder="••••••••" autocomplete="new-password"
                               minlength="8" maxlength="72" required
                               aria-describedby="pw-match">
                        <button type="button" class="pw-reveal" data-target="admin_pass2"
                                aria-label="Show password" aria-pressed="false">
                            <svg class="pw-eye-show" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-hide" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    <div class="field-hint" id="pw-match"></div>
                </div>

                <hr style="border:none;border-top:1px solid var(--border);margin:20px 0 16px;">

                <div class="field">
                    <label class="field-check">
                        <input type="checkbox" name="hide_system" value="1" <?= $values['hide_system'] === '1' ? 'checked' : '' ?>>
                        Hide system databases (information_schema, mysql, sys, etc.)
                    </label>
                </div>
                <div class="field">
                    <label class="field-check">
                        <input type="checkbox" name="force_https" value="1" <?= $values['force_https'] === '1' ? 'checked' : '' ?>>
                        Force HTTPS (enable if you have an SSL certificate)
                    </label>
                </div>
            </div>
            <div class="installer-footer">
                <button type="button" class="btn-back" onclick="history.back()">← Back</button>
                <button type="submit" class="btn-install">Install Ledger →</button>
            </div>
        </form>

        <!-- ═══ Step 3: Complete ═══ -->
        <?php elseif ($step === 3 && empty($errors)): ?>
        <div class="installer-body">
            <div class="success-card">
                <div class="success-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="success-title">Installation Complete</div>
                <div class="success-text">
                    Ledger is ready. Sign in with your new admin account.
                </div>
                <a href="index.php" class="btn-install" style="text-decoration:none;">
                    Open Ledger →
                </a>
            </div>
        </div>
        <div class="installer-footer" style="justify-content:center;">
            <span class="step-label">config.php has been created</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
// Password strength meter — runs only if the relevant elements are present
// (i.e. step 2 of the installer). zxcvbn-inspired scoring without the library.
(function () {
    'use strict';

    const pwInput = document.getElementById('admin_pass');
    if (!pwInput) return; // not on the admin-account step

    const pw2Input  = document.getElementById('admin_pass2');
    const userInput = document.getElementById('admin_user');
    const meter     = document.getElementById('pw-meter');
    const meterLbl  = meter.querySelector('.pw-meter-label');
    const rulesEl   = document.getElementById('pw-rules');
    const matchEl   = document.getElementById('pw-match');
    const submitBtn = document.querySelector('form button[type="submit"], form input[type="submit"]');

    // Minimal common-password list for client-side preview (server has the
    // full ~100-entry list — this is just enough to give immediate feedback
    // on the absolute worst choices)
    const COMMON = new Set([
        '123456', '123456789', 'password', 'qwerty', '12345678', '111111',
        '12345', 'qwerty123', 'admin', 'letmein', 'welcome', 'monkey',
        'abc123', 'password1', 'iloveyou', 'sunshine', 'master', '666666',
        'dragon', 'football', 'qwertyuiop', 'admin123', 'password123',
        'p@ssword', 'p@ssw0rd', 'changeme', 'ledger', 'mysql', 'root',
        'toor', 'pass', 'guest', 'test', 'demo', 'user', 'welcome1',
    ]);

    /**
     * Score a password 0–5. Inspired by zxcvbn but ~50 lines instead of 800kb.
     * 0 = empty, 1 = very weak, 2 = weak, 3 = fair, 4 = good, 5 = strong.
     */
    function score(pw, username) {
        if (!pw) return 0;
        if (COMMON.has(pw.toLowerCase())) return 1;
        if (username && pw.toLowerCase() === username.toLowerCase()) return 1;
        if (pw.length < 8) return 1;

        let s = 0;

        // Length is the dominant factor — every modern password guide says so
        if (pw.length >= 8)  s += 1;
        if (pw.length >= 12) s += 1;
        if (pw.length >= 16) s += 1;
        if (pw.length >= 20) s += 1;

        // Character class diversity (bounded contribution)
        let classes = 0;
        if (/[a-z]/.test(pw)) classes++;
        if (/[A-Z]/.test(pw)) classes++;
        if (/[0-9]/.test(pw)) classes++;
        if (/[^a-zA-Z0-9]/.test(pw)) classes++;
        if (classes >= 3) s += 1;

        // Penalties for predictable patterns
        if (/^(.)\1+$/.test(pw))                  s -= 2; // all same character
        if (/^(012|123|234|345|456|567|678|789|890|abc|qwe|asd|zxc)/i.test(pw)) s -= 1;
        if (/^\d+$/.test(pw))                     s -= 1; // digits only
        if (/^[a-z]+$/i.test(pw) && pw.length < 16) s -= 1; // lowercase letters only

        return Math.max(1, Math.min(5, s));
    }

    const LABELS = {
        0: 'Enter a password',
        1: 'Very weak — easily guessed',
        2: 'Weak — try a longer or less common password',
        3: 'Fair — could be stronger',
        4: 'Good',
        5: 'Strong',
    };

    function updateRules(pw, username) {
        const len   = pw.length;
        const rules = {
            length:    len >= 8,
            max:       len > 0 && len <= 72,
            notcommon: len > 0 && !COMMON.has(pw.toLowerCase()),
            notuser:   len > 0 && (!username || pw.toLowerCase() !== username.toLowerCase()),
        };
        rulesEl.querySelectorAll('li[data-rule]').forEach((li) => {
            const r = li.getAttribute('data-rule');
            li.classList.toggle('is-met', !!rules[r]);
        });
        return Object.values(rules).every(Boolean);
    }

    function updateMatch() {
        if (!matchEl || !pw2Input) return true;
        const pw = pwInput.value;
        const pw2 = pw2Input.value;
        matchEl.classList.remove('is-match', 'is-mismatch');
        matchEl.textContent = '';
        if (!pw2) return false;
        if (pw === pw2) {
            matchEl.textContent = 'Passwords match';
            matchEl.classList.add('is-match');
            return true;
        }
        matchEl.textContent = 'Passwords do not match';
        matchEl.classList.add('is-mismatch');
        return false;
    }

    function refresh() {
        const pw       = pwInput.value;
        const username = userInput ? userInput.value : '';
        const s        = score(pw, username);

        meter.setAttribute('data-score', String(s));
        meterLbl.textContent = LABELS[s];

        const rulesOk = updateRules(pw, username);
        const matchOk = updateMatch();

        // Disable submit if password is too weak (score < 3) or rules fail or no match
        if (submitBtn) {
            const ok = rulesOk && matchOk && s >= 3;
            submitBtn.disabled = !ok;
            submitBtn.style.opacity = ok ? '' : '0.55';
            submitBtn.style.cursor  = ok ? '' : 'not-allowed';
        }
    }

    pwInput.addEventListener('input', refresh);
    if (pw2Input)  pw2Input.addEventListener('input', refresh);
    if (userInput) userInput.addEventListener('input', refresh);

    // ─── Reveal-eye toggles ──────────────────────────────────────────────
    document.querySelectorAll('.pw-reveal').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.getAttribute('data-target'));
            if (!target) return;
            const showing = target.type === 'text';
            target.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-pressed', String(!showing));
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            const showIcon = btn.querySelector('.pw-eye-show');
            const hideIcon = btn.querySelector('.pw-eye-hide');
            if (showIcon) showIcon.style.display = showing ? '' : 'none';
            if (hideIcon) hideIcon.style.display = showing ? 'none' : '';
        });
    });

    // ─── Generate strong password ───────────────────────────────────────
    const genBtn = document.getElementById('pw-generate');
    if (genBtn) {
        // Curated alphabet — avoids ambiguous chars (0/O, 1/l/I) and shell-unfriendly
        // symbols (\ " ' ` $) that commonly cause copy/paste pain across terminals
        // and password managers. Still gives ~6 bits per character.
        const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%&*()-_=+[]{}:,.<>?/';
        const LENGTH   = 20;

        const generate = () => {
            // crypto.getRandomValues is cryptographically secure — Math.random is not
            const buf = new Uint32Array(LENGTH);
            crypto.getRandomValues(buf);
            let out = '';
            for (let i = 0; i < LENGTH; i++) {
                out += ALPHABET[buf[i] % ALPHABET.length];
            }
            return out;
        };

        const reveal = (input) => {
            input.type = 'text';
            const btn = document.querySelector(`.pw-reveal[data-target="${input.id}"]`);
            if (!btn) return;
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('aria-label', 'Hide password');
            const showIcon = btn.querySelector('.pw-eye-show');
            const hideIcon = btn.querySelector('.pw-eye-hide');
            if (showIcon) showIcon.style.display = 'none';
            if (hideIcon) hideIcon.style.display = '';
        };

        genBtn.addEventListener('click', async () => {
            const pw = generate();

            // Fill both fields and reveal them so the user can verify the value
            pwInput.value = pw;
            if (pw2Input) pw2Input.value = pw;
            reveal(pwInput);
            if (pw2Input) reveal(pw2Input);
            refresh();

            // Copy to clipboard so the user can paste it into a password manager
            // before submitting. Best-effort: silently skip if clipboard API isn't
            // available (e.g. HTTP context, older browser).
            let copied = false;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                try {
                    await navigator.clipboard.writeText(pw);
                    copied = true;
                } catch { /* user denied permission or non-secure context */ }
            }

            // Feedback: show "✓ Copied" briefly if copy worked, else just "Generated"
            const originalText = genBtn.querySelector('span').textContent;
            genBtn.querySelector('span').textContent = copied ? 'Copied' : 'Generated';
            genBtn.classList.add('is-copied');
            setTimeout(() => {
                genBtn.querySelector('span').textContent = originalText;
                genBtn.classList.remove('is-copied');
            }, 2000);
        });
    }

    refresh();
})();
</script>
</body>
</html>
