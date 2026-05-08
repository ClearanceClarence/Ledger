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
            $errors[] = 'Connection failed: ' . $e->getMessage();
            $step = 1; // Stay on step 1
        }
    } else {
        $step = 1;
    }
}

// Step 3: Validate admin + write config
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($values['admin_user'])) $errors[] = 'Admin username is required.';
    if (strlen($values['admin_user']) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (empty($values['admin_pass'])) $errors[] = 'Admin password is required.';
    if (strlen($values['admin_pass']) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($values['admin_pass'] !== $values['admin_pass2']) $errors[] = 'Passwords do not match.';

    // Check common weak passwords
    $weak = ['password', '123456', 'admin', 'root', 'ledger', 'letmein', 'qwerty'];
    if (in_array(strtolower($values['admin_pass']), $weak)) {
        $errors[] = 'That password is too common. Choose something stronger.';
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
                           minlength="3" required autofocus>
                    <div class="field-hint">Minimum 3 characters</div>
                </div>
                <div class="field">
                    <label class="field-label" for="admin_pass">Password</label>
                    <input type="password" id="admin_pass" name="admin_pass" class="field-input"
                           placeholder="••••••••" autocomplete="new-password" minlength="6" required>
                    <div class="field-hint">Minimum 6 characters. Stored as a bcrypt hash.</div>
                </div>
                <div class="field">
                    <label class="field-label" for="admin_pass2">Confirm Password</label>
                    <input type="password" id="admin_pass2" name="admin_pass2" class="field-input"
                           placeholder="••••••••" autocomplete="new-password" minlength="6" required>
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
</body>
</html>
