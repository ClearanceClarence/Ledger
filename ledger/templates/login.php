<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($appName) ?> — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="themes/dark-industrial/style.css">
    <?php if ($activeTheme !== 'dark-industrial'): ?>
    <link rel="stylesheet" href="themes/<?= h($activeTheme) ?>/style.css">
    <?php endif; ?>
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-root);
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 380px;
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .login-header {
            padding: 28px 28px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }
        .login-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .login-logo-text {
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 22px;
            color: var(--accent);
        }
        .login-subtitle {
            font-size: var(--font-size-sm);
            color: var(--text-muted);
        }
        .login-body {
            padding: 24px 28px;
        }
        .login-field {
            margin-bottom: 16px;
        }
        .login-label {
            display: block;
            font-size: var(--font-size-xs);
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .login-input {
            width: 100%;
            padding: 10px 12px;
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
        .login-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(59,130,246,0.12);
        }
        .login-btn {
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: var(--text-inverse);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: var(--font-size-base);
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .login-btn:hover { opacity: 0.9; }
        .login-error {
            padding: 10px 14px;
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            border-radius: var(--radius-md);
            color: var(--danger);
            font-size: var(--font-size-sm);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-footer {
            padding: 14px 28px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: var(--font-size-xs);
            color: var(--text-muted);
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">
                <?= ledger_logo(30, 'var(--accent)') ?>
                <span class="login-logo-text"><?= h($appName) ?></span>
            </div>
            <div class="login-subtitle">Sign in to manage your databases</div>
        </div>

        <div class="login-body">
            <?php if (!empty($loginError)): ?>
            <div class="login-error">
                <?= icon('alert-triangle', 14) ?>
                <?= h($loginError) ?>
            </div>
            <?php endif; ?>

            <form method="post" action="?action=login" autocomplete="on">
                <?= $auth->csrfField() ?>
                <div class="login-field">
                    <label class="login-label" for="login-user">Username</label>
                    <input type="text" id="login-user" name="username" class="login-input"
                           placeholder="admin" autocomplete="username" autofocus required>
                </div>
                <div class="login-field">
                    <label class="login-label" for="login-pass">Password</label>
                    <input type="password" id="login-pass" name="password" class="login-input"
                           placeholder="••••••••" autocomplete="current-password" required>
                </div>
                <button type="submit" class="login-btn">
                    <?= icon('key', 16) ?>
                    Sign In
                </button>
            </form>
        </div>

        <div class="login-footer">
            <?= h($appName) ?> v<?= h(LEDGER_VERSION) ?>
        </div>
    </div>
</div>
</body>
</html>
