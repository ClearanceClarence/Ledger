<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($appName) ?> — <?= h($currentDb ?? 'Server') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php
    // Load custom fonts
    $fontConfig = $config['app']['fonts'] ?? [];
    $fontStyles = ledger_font_styles($fontConfig);
    if ($fontStyles['link']) echo $fontStyles['link'] . "\n";
    ?>
    <!-- Base theme (always loaded first) -->
    <link rel="stylesheet" href="themes/dark-industrial/style.css" id="base-theme">
    <?php if ($activeTheme !== 'dark-industrial'): ?>
    <!-- Active theme overrides -->
    <link rel="stylesheet" href="themes/<?= h($activeTheme) ?>/style.css" id="active-theme">
    <?php endif; ?>
    <?php if ($fontStyles['css']): ?>
    <style id="font-overrides"><?= $fontStyles['css'] ?></style>
    <?php endif; ?>
    <?php if (isset($auth)): ?>
    <?= $auth->csrfMeta() ?>
    <?php endif; ?>
</head>
<body>
<div class="app-wrapper">

    <!-- Header -->
    <header class="app-header">
        <div class="header-left">
            <a href="?" class="logo">
                <?= ledger_logo(22, 'var(--accent)') ?>
                <span class="logo-text"><?= h($appName) ?></span>
                <span class="logo-version">v<?= h($appVersion) ?></span>
            </a>
            <div class="header-meta">
                <span class="header-chip"><?= icon('server', 11) ?> <?= h($serverHost) ?>:<?= h($config['db']['port'] ?? '3306') ?></span>
                <span class="header-chip"><?= icon('database', 11) ?> <?= h($serverVersion) ?> · <?= h($charset) ?></span>
                <span class="header-chip"><?= icon('layers', 11) ?> <?= count($databases) ?> db<?= count($databases) !== 1 ? 's' : '' ?></span>
                <?php if ($uptime): ?>
                <span class="header-chip"><?= icon('clock', 11) ?> <?= format_uptime($uptime) ?></span>
                <?php endif; ?>
                <span class="header-chip dim"><?= icon('code', 11) ?> PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></span>
            </div>
        </div>
        <div class="header-right">
            <?php if (isset($auth) && $auth->isReadOnly()): ?>
            <span class="header-chip" style="color:var(--warning);border-color:var(--warning);"><?= icon('eye', 11) ?> Read-Only</span>
            <?php endif; ?>
            <select class="theme-select" id="theme-selector" onchange="Ledger.switchTheme(this.value)">
                <?php
                    $lightThemes = $darkThemes = [];
                    foreach ($themes as $slug => $theme) {
                        if (($theme['type'] ?? 'dark') === 'light') {
                            $lightThemes[$slug] = $theme;
                        } else {
                            $darkThemes[$slug] = $theme;
                        }
                    }
                    uasort($lightThemes, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                    uasort($darkThemes, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                ?>
                <optgroup label="Light">
                    <?php foreach ($lightThemes as $slug => $theme): ?>
                    <option value="<?= h($slug) ?>" <?= $slug === $activeTheme ? 'selected' : '' ?>><?= h($theme['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Dark">
                    <?php foreach ($darkThemes as $slug => $theme): ?>
                    <option value="<?= h($slug) ?>" <?= $slug === $activeTheme ? 'selected' : '' ?>><?= h($theme['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <span class="status-dot online"></span>
            <span class="header-chip"><?= icon('activity', 11) ?> Connected</span>
            <?php if (isset($auth) && $auth->isAuthRequired() && $auth->isLoggedIn()): ?>
            <button type="button" class="header-chip header-chip-btn" id="profile-btn" title="Profile"><?= icon('key', 11) ?> <?= h($auth->getUsername()) ?></button>
            <a href="?action=logout" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:var(--font-size-xs);"><?= icon('x', 12) ?> Logout</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="app-body">

        <!-- Sidebar -->
        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Area -->
        <main class="main-area">

            <!-- Tab Bar -->
            <div class="tab-bar">
                <div class="tab-group">
                    <?php
                    // Table-level tabs (only show when a table is selected)
                    $tableTabs = [
                        'browse'     => ['icon' => 'table', 'label' => 'Browse'],
                        'structure'  => ['icon' => 'columns', 'label' => 'Structure'],
                        'sql'        => ['icon' => 'terminal', 'label' => 'SQL'],
                        'search'     => ['icon' => 'search', 'label' => 'Search'],
                        'er'         => ['icon' => 'share', 'label' => 'ER Diagram'],
                        'info'       => ['icon' => 'info', 'label' => 'Info'],
                        'operations' => ['icon' => 'settings', 'label' => 'Operations'],
                        'export'     => ['icon' => 'download', 'label' => 'Export'],
                        'import'     => ['icon' => 'upload', 'label' => 'Import'],
                    ];
                    foreach ($tableTabs as $tabId => $tab):
                        $isActive = ($activeTab === $tabId);

                        // Structure + Info + Operations require a table
                        if (in_array($tabId, ['structure', 'info', 'operations']) && !$currentTable) continue;
                        // Search + ER require a database
                        if (in_array($tabId, ['search', 'er']) && !$currentDb) continue;

                        if ($tabId === 'sql') {
                            $href = $currentDb ? "?db=" . urlencode($currentDb) . "&tab=sql" : "?tab=sql";
                        } elseif (in_array($tabId, ['export', 'import'])) {
                            $href = "?tab={$tabId}";
                            if ($currentDb) $href .= "&db=" . urlencode($currentDb);
                            if ($currentTable) $href .= "&table=" . urlencode($currentTable);
                        } elseif ($tabId === 'search') {
                            $href = "?db=" . urlencode($currentDb) . "&tab=search";
                        } elseif ($tabId === 'er') {
                            $href = "?db=" . urlencode($currentDb) . "&tab=er";
                        } else {
                            $href = "?db=" . urlencode($currentDb ?? '') . "&table=" . urlencode($currentTable ?? '') . "&tab={$tabId}";
                        }
                    ?>
                    <a href="<?= $href ?>" class="tab-btn <?= $isActive ? 'active' : '' ?>" data-tab="<?= $tabId ?>">
                        <?= icon($tab['icon'], 14) ?>
                        <?= $tab['label'] ?>
                    </a>
                    <?php endforeach; ?>
                    <a href="?tab=server" class="tab-btn <?= $activeTab === 'server' ? 'active' : '' ?>" data-tab="server">
                        <?= icon('server', 14) ?> Server
                    </a>
                    <a href="?tab=processes" class="tab-btn <?= $activeTab === 'processes' ? 'active' : '' ?>" data-tab="processes">
                        <?= icon('activity', 14) ?> Processes
                    </a>
                    <a href="?tab=settings" class="tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>" data-tab="settings">
                        <?= icon('settings', 14) ?> Settings
                    </a>
                </div>
                <?php if ($currentDb): ?>
                <div class="breadcrumb">
                    <span class="db"><?= h($currentDb) ?></span>
                    <?php if ($currentTable): ?>
                    <span class="sep">›</span>
                    <span class="tbl"><?= h($currentTable) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Content Area -->
            <div class="content-area fade-in">
                <?php include $contentTemplate; ?>
            </div>
        </main>
    </div>

    <!-- Status Bar -->
    <footer class="status-bar">
        <div class="status-left">
            <span class="status-dot online"></span>
            <span id="status-message">Ready</span>
        </div>
        <div class="status-right">
            <span><?= h($appName) ?> © <?= date('Y') ?></span>
            <span style="color: var(--text-muted);">|</span>
            <span>PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?> · MySQL <?= h($serverVersion) ?></span>
        </div>
    </footer>
</div>

<script src="js/qr.js"></script>
<script src="js/ledger.js"></script>
<?php if (isset($auth) && $auth->isAuthRequired() && $auth->isLoggedIn()): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('profile-btn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        var username = <?= json_encode($auth->getUsername()) ?>;
        var csrf = Ledger.getCsrfToken();
        var has2fa = <?= json_encode((bool)$auth->getUserTotpSecret($auth->getUsername())) ?>;

        Ledger.closeModal();
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';
        overlay.innerHTML =
            '<div class="modal-box" style="max-width:480px;">' +
                '<div class="modal-header">' +
                    '<span class="modal-title"><?= icon('key', 14) ?> Profile</span>' +
                    '<button class="modal-close" data-action="cancel">&times;</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<div class="settings-field">' +
                        '<label class="settings-label">Username</label>' +
                        '<input type="text" class="settings-input" value="' + username + '" disabled style="opacity:0.6;font-family:var(--font-mono);">' +
                    '</div>' +

                    // 2FA section
                    '<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">' +
                            '<div style="font-size:var(--font-size-sm);font-weight:600;color:var(--text-secondary);">Two-Factor Authentication</div>' +
                            '<span style="font-size:var(--font-size-xs);padding:2px 8px;border-radius:10px;font-weight:600;' +
                                (has2fa ? 'background:var(--accent-bg);color:var(--accent);' : 'background:var(--bg-panel-alt);color:var(--text-muted);') + '">' +
                                (has2fa ? 'Enabled' : 'Disabled') + '</span>' +
                        '</div>' +
                        '<div id="pf-2fa-area">' +
                            (has2fa
                                ? '<p style="font-size:var(--font-size-xs);color:var(--text-muted);margin:0 0 10px;">TOTP is active. Disabling will remove the second factor from your account.</p>' +
                                  '<button type="button" class="btn btn-danger btn-sm" id="pf-2fa-disable">Disable 2FA</button>'
                                : '<p style="font-size:var(--font-size-xs);color:var(--text-muted);margin:0 0 10px;">Add an extra layer of security with an authenticator app (Google Authenticator, Authy, 1Password, etc.)</p>' +
                                  '<button type="button" class="btn btn-primary btn-sm" id="pf-2fa-setup">Set up 2FA</button>'
                            ) +
                        '</div>' +
                    '</div>' +

                    // Change password section
                    '<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);">' +
                        '<div style="font-size:var(--font-size-sm);font-weight:600;color:var(--text-secondary);margin-bottom:10px;">Change password</div>' +
                        '<div class="settings-field">' +
                            '<label class="settings-label">Current password</label>' +
                            '<input type="password" id="pf-current" class="settings-input" autocomplete="current-password">' +
                        '</div>' +
                        '<div class="settings-field" style="margin-top:8px;">' +
                            '<label class="settings-label">New password</label>' +
                            '<input type="password" id="pf-new" class="settings-input" autocomplete="new-password" placeholder="Minimum 6 characters">' +
                        '</div>' +
                        '<div class="settings-field" style="margin-top:8px;">' +
                            '<label class="settings-label">Confirm new password</label>' +
                            '<input type="password" id="pf-confirm" class="settings-input" autocomplete="new-password">' +
                        '</div>' +
                        '<div id="pf-err" class="error-box" style="margin-top:10px;display:none;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                        '<div id="pf-ok" class="success-box" style="margin-top:10px;display:none;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                    '</div>' +
                '</div>' +
                '<div class="modal-footer">' +
                    '<a href="?action=logout" class="btn btn-ghost modal-btn"><?= icon('x', 12) ?> Logout</a>' +
                    '<div style="flex:1;"></div>' +
                    '<button class="btn btn-ghost modal-btn" data-action="cancel">Close</button>' +
                    '<button class="btn btn-primary modal-btn" id="pf-save">Update password</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

        var curEl = overlay.querySelector('#pf-current');
        var newEl = overlay.querySelector('#pf-new');
        var cnfEl = overlay.querySelector('#pf-confirm');
        var errEl = overlay.querySelector('#pf-err');
        var okEl  = overlay.querySelector('#pf-ok');
        curEl.focus();

        function showErr(msg) { errEl.textContent = msg; errEl.style.display = ''; okEl.style.display = 'none'; }
        function showOk(msg)  { okEl.textContent  = msg; okEl.style.display  = ''; errEl.style.display = 'none'; }

        // 2FA Setup Handler
        var setupBtn = overlay.querySelector('#pf-2fa-setup');
        var disableBtn = overlay.querySelector('#pf-2fa-disable');
        var area2fa = overlay.querySelector('#pf-2fa-area');

        if (setupBtn) {
            setupBtn.addEventListener('click', function() {
                setupBtn.disabled = true;
                setupBtn.textContent = 'Generating…';
                var fd = new FormData();
                fd.append('action', 'setup_2fa');
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { showErr(data.error); setupBtn.disabled = false; setupBtn.textContent = 'Set up 2FA'; return; }
                        area2fa.innerHTML =
                            '<div style="text-align:center;margin-bottom:14px;">' +
                                '<div style="display:inline-block;padding:12px;background:white;border-radius:8px;border:1px solid var(--border);">' +
                                    (typeof LedgerQR !== 'undefined' ? LedgerQR.toSVG(data.uri, 200) : '<div style="color:var(--text-muted);">QR unavailable</div>') +
                                '</div>' +
                            '</div>' +
                            '<div style="text-align:center;margin-bottom:14px;">' +
                                '<div style="font-size:var(--font-size-xs);color:var(--text-muted);margin-bottom:4px;">Or enter this key manually:</div>' +
                                '<code style="font-size:var(--font-size-sm);letter-spacing:0.1em;color:var(--accent);font-weight:700;user-select:all;">' + data.secret + '</code>' +
                            '</div>' +
                            '<div class="settings-field">' +
                                '<label class="settings-label">Enter 6-digit code to confirm</label>' +
                                '<input type="text" id="pf-2fa-code" class="settings-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="000000" style="text-align:center;letter-spacing:0.2em;font-size:18px;font-weight:700;">' +
                            '</div>' +
                            '<button type="button" class="btn btn-primary btn-sm" id="pf-2fa-confirm" style="margin-top:10px;width:100%;">Confirm &amp; enable 2FA</button>';

                        var codeEl = area2fa.querySelector('#pf-2fa-code');
                        var confirmBtn = area2fa.querySelector('#pf-2fa-confirm');
                        codeEl.focus();

                        confirmBtn.addEventListener('click', function() {
                            var code = codeEl.value.trim();
                            if (code.length !== 6 || !/^\d{6}$/.test(code)) { showErr('Enter a valid 6-digit code.'); return; }
                            confirmBtn.disabled = true;
                            var fd2 = new FormData();
                            fd2.append('action', 'confirm_2fa');
                            fd2.append('code', code);
                            fd2.append('secret', data.secret);
                            fd2.append('_csrf_token', csrf);
                            fetch('ajax.php', { method: 'POST', body: fd2 })
                                .then(function(r) { return r.json(); })
                                .then(function(resp) {
                                    if (resp.error) { showErr(resp.error); confirmBtn.disabled = false; return; }
                                    showOk('Two-factor authentication enabled!');
                                    area2fa.innerHTML =
                                        '<p style="font-size:var(--font-size-xs);color:var(--accent);margin:0;">✓ 2FA is now active. You will need your authenticator app on next login.</p>';
                                    has2fa = true;
                                });
                        });
                        codeEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') confirmBtn.click(); });
                    });
            });
        }

        if (disableBtn) {
            disableBtn.addEventListener('click', function() {
                if (!confirm('Are you sure you want to disable two-factor authentication?')) return;
                disableBtn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'disable_2fa');
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { showErr(data.error); disableBtn.disabled = false; return; }
                        showOk('Two-factor authentication disabled.');
                        area2fa.innerHTML =
                            '<p style="font-size:var(--font-size-xs);color:var(--text-muted);margin:0 0 10px;">Add an extra layer of security with an authenticator app.</p>' +
                            '<button type="button" class="btn btn-primary btn-sm" id="pf-2fa-setup">Set up 2FA</button>';
                        has2fa = false;
                        // Re-bind setup button
                        area2fa.querySelector('#pf-2fa-setup').addEventListener('click', setupBtn.onclick);
                    });
            });
        }

        function save() {
            errEl.style.display = 'none'; okEl.style.display = 'none';
            var cur = curEl.value, nw = newEl.value, cn = cnfEl.value;
            if (!cur || !nw || !cn) { showErr('All fields are required.'); return; }
            if (nw.length < 6) { showErr('New password must be at least 6 characters.'); return; }
            if (nw !== cn) { showErr('New passwords do not match.'); return; }
            if (nw === cur) { showErr('New password must differ from current.'); return; }

            var fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('current', cur);
            fd.append('new', nw);
            fd.append('_csrf_token', csrf);
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { showErr(data.error); return; }
                    showOk('Password updated.');
                    curEl.value = ''; newEl.value = ''; cnfEl.value = '';
                    Ledger.setStatus('Password updated successfully.');
                });
        }

        overlay.querySelector('#pf-save').addEventListener('click', save);
        [curEl, newEl, cnfEl].forEach(function(el) {
            el.addEventListener('keydown', function(e) { if (e.key === 'Enter') save(); });
        });

        function close() {
            overlay.classList.remove('modal-visible');
            setTimeout(function() { overlay.remove(); }, 150);
        }
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay || (e.target.dataset && e.target.dataset.action === 'cancel') ||
                (e.target.closest && e.target.closest('[data-action="cancel"]'))) close();
        });
    });
});
</script>
<?php endif; ?>
</body>
</html>
