<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($appName) ?> — Two-Factor Authentication</title>
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
        .login-body { padding: 24px 28px; }
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
        .totp-input {
            font-size: 28px;
            text-align: center;
            letter-spacing: 0.3em;
            font-weight: 700;
            padding: 14px;
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
            margin-top: 16px;
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
        .totp-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: var(--accent-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: var(--accent);
        }
        .totp-hint {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            text-align: center;
            margin-top: 12px;
            line-height: 1.5;
        }
        .totp-cancel {
            display: block;
            text-align: center;
            margin-top: 12px;
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            text-decoration: none;
        }
        .totp-cancel:hover { color: var(--text-secondary); }
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
            <div class="login-subtitle">Two-Factor Authentication</div>
        </div>

        <div class="login-body">
            <div class="totp-icon">
                <?= icon('key', 28) ?>
            </div>

            <?php if (!empty($loginError)): ?>
            <div class="login-error">
                <?= icon('alert-triangle', 14) ?>
                <?= h($loginError) ?>
            </div>
            <?php endif; ?>

            <p style="text-align:center;font-size:var(--font-size-sm);color:var(--text-secondary);margin:0 0 16px;">
                Enter the 6-digit code from your authenticator app
            </p>

            <form method="post" action="?action=verify_2fa" autocomplete="off">
                <?= $auth->csrfField() ?>
                <?php
                    // Carry the return URL through 2FA verification. Captured during
                    // the initial GET when the user landed on the protected URL.
                    $returnTo = $_SESSION['ledger_login_redirect'] ?? '';
                    if (!is_string($returnTo)) $returnTo = '';
                ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <div style="margin-bottom:16px;">
                    <input type="text" name="totp_code" class="login-input totp-input"
                           placeholder="000000"
                           maxlength="6"
                           pattern="[0-9]{6}"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           autofocus
                           required>
                </div>
                <button type="submit" class="login-btn">
                    <?= icon('check', 16) ?>
                    Verify
                </button>
            </form>

            <div class="totp-hint">
                Open Google Authenticator, Authy, or your preferred<br>
                authenticator app to get your verification code.
            </div>

            <a href="?action=logout" class="totp-cancel">Cancel and sign in again</a>
        </div>

        <div class="login-footer">
            <?= h($appName) ?> v<?= h(LEDGER_VERSION) ?>
        </div>
    </div>
</div>

<script>
// Auto-submit when 6 digits are entered
document.querySelector('.totp-input').addEventListener('input', function() {
    // Strip non-digits
    this.value = this.value.replace(/\D/g, '');
    if (this.value.length === 6) {
        this.closest('form').submit();
    }
});
</script>
</body>
</html>
