<?php
/**
 * Ledger — Settings Page
 * Allows editing config.php from the UI.
 *
 * The POST handler lives in settings_save.php and is invoked from
 * index.php BEFORE layout renders, so redirects work and the sidebar
 * reflects the new config immediately.
 *
 * On entry here, index.php may have set $settingsErr (if save failed).
 */

$settingsMsg = '';
if (!isset($settingsErr)) $settingsErr = '';

// Flash message after successful save → redirect
if (!empty($_SESSION['settings_msg'])) {
    $settingsMsg = $_SESSION['settings_msg'];
    unset($_SESSION['settings_msg']);
}

$sec = $config['security'] ?? [];
$app = $config['app'] ?? [];
$db  = $config['db'] ?? [];
?>

<?php if ($settingsMsg): ?>
<div class="success-box" style="margin-bottom:16px;">
    <?= icon('check', 14) ?> <?= h($settingsMsg) ?>
</div>
<?php endif; ?>

<?php if ($settingsErr): ?>
<div class="error-box" style="margin-bottom:16px;">
    <strong>Error:</strong> <?= h($settingsErr) ?>
</div>
<?php endif; ?>

<form method="post" action="?tab=settings" id="settings-form">
    <?php if (isset($auth)): ?><?= $auth->csrfField() ?><?php endif; ?>
    <input type="hidden" name="_settings_action" value="save">

    <!-- Database Connection -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('database', 16) ?> Database Connection</h3>
        <div class="settings-grid">
            <div class="settings-field" style="flex:3;">
                <label class="settings-label" for="s-db-host">Host</label>
                <input type="text" id="s-db-host" name="db_host" class="settings-input"
                       value="<?= h($db['host'] ?? '127.0.0.1') ?>">
            </div>
            <div class="settings-field" style="flex:1;">
                <label class="settings-label" for="s-db-port">Port</label>
                <input type="number" id="s-db-port" name="db_port" class="settings-input"
                       value="<?= h($db['port'] ?? 3306) ?>">
            </div>
        </div>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label" for="s-db-user">Username</label>
                <input type="text" id="s-db-user" name="db_user" class="settings-input"
                       value="<?= h($db['username'] ?? 'root') ?>" autocomplete="off">
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-db-pass">Password</label>
                <input type="password" id="s-db-pass" name="db_pass" class="settings-input"
                       value="" placeholder="••••••• (unchanged)" autocomplete="off"
                       onchange="document.getElementById('db-pass-changed').value='1'">
                <input type="hidden" id="db-pass-changed" name="db_pass_changed" value="0">
                <div class="settings-hint">Leave empty to keep current password</div>
            </div>
        </div>
    </div>

    <!-- Application -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('layers', 16) ?> Application</h3>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label" for="s-theme">Default Theme</label>
                <select id="s-theme" name="default_theme" class="settings-input">
                    <?php foreach ($themes as $slug => $theme): ?>
                    <option value="<?= h($slug) ?>" <?= ($app['default_theme'] ?? '') === $slug ? 'selected' : '' ?>>
                        <?= h($theme['name']) ?> (<?= h($theme['type'] ?? '') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-rpp">Rows Per Page</label>
                <input type="number" id="s-rpp" name="rows_per_page" class="settings-input"
                       value="<?= (int)($app['rows_per_page'] ?? 50) ?>" min="10" max="500">
            </div>
        </div>
        <div class="settings-check-group">
            <label class="settings-check">
                <input type="checkbox" name="enable_export" <?= !empty($app['enable_export']) ? 'checked' : '' ?>>
                <?= icon('download', 14) ?> Enable data export (SQL / CSV)
            </label>
            <label class="settings-check">
                <input type="checkbox" name="version_check" <?= ($app['version_check'] ?? true) ? 'checked' : '' ?>>
                <?= icon('refresh', 14) ?> Check for updates
            </label>
            <div class="settings-help" style="font-size:0.8rem; color:var(--text-muted); margin:-0.25rem 0 0 1.7rem; line-height:1.45;">
                When enabled, your browser checks <code>tryledger.dev</code> once
                per day for new Ledger releases. The request sends no parameters
                and no cookies — only the standard things any web server logs.
            </div>
        </div>
    </div>

    <!-- Authentication -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('key', 16) ?> Authentication</h3>
        <div class="settings-check-group">
            <label class="settings-check">
                <input type="checkbox" name="require_auth" <?= !empty($sec['require_auth']) ? 'checked' : '' ?>>
                <?= icon('key', 14) ?> Require login (recommended for production)
            </label>
            <label class="settings-check">
                <input type="checkbox" name="csrf_enabled" <?= ($sec['csrf_enabled'] ?? true) ? 'checked' : '' ?>>
                <?= icon('hash', 14) ?> CSRF protection on forms and AJAX
            </label>
            <label class="settings-check<?= (!$auth->isHttps() && empty($sec['force_https'])) ? ' settings-check--disabled' : '' ?>">
                <input type="checkbox" name="force_https"
                    <?= !empty($sec['force_https']) ? 'checked' : '' ?>
                    <?= (!$auth->isHttps() && empty($sec['force_https'])) ? 'disabled title="Open Ledger via https:// to enable this — enabling it over plain HTTP would lock you out if HTTPS isn\'t working on this server."' : '' ?>>
                <?= icon('external-link', 14) ?> Force HTTPS redirect
                <?php if (!$auth->isHttps() && empty($sec['force_https'])): ?>
                    <span class="settings-check-hint">requires an HTTPS session to enable</span>
                <?php endif; ?>
            </label>
            <label class="settings-check">
                <input type="checkbox" name="read_only" <?= !empty($sec['read_only']) ? 'checked' : '' ?>>
                <?= icon('eye', 14) ?> Read-only mode (blocks INSERT/UPDATE/DELETE/DROP)
            </label>
            <label class="settings-check">
                <input type="checkbox" name="query_log" <?= !empty($sec['query_log']) ? 'checked' : '' ?>>
                <?= icon('file-text', 14) ?> Query audit log (logs/queries.log)
            </label>
        </div>
        <div class="settings-grid" style="margin-top:12px;">
            <div class="settings-field">
                <label class="settings-label" for="s-session">Session Timeout (seconds)</label>
                <input type="number" id="s-session" name="session_lifetime" class="settings-input"
                       value="<?= (int)($sec['session_lifetime'] ?? 3600) ?>" min="300">
                <div class="settings-hint">Default: 3600 (1 hour)</div>
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-maxlogin">Max Login Attempts</label>
                <input type="number" id="s-maxlogin" name="max_login_attempts" class="settings-input"
                       value="<?= (int)($sec['max_login_attempts'] ?? 5) ?>" min="1">
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-lockout">Lockout Duration (seconds)</label>
                <input type="number" id="s-lockout" name="lockout_duration" class="settings-input"
                       value="<?= (int)($sec['lockout_duration'] ?? 300) ?>" min="30">
            </div>
        </div>
    </div>

    <!-- Users -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('key', 16) ?> User Accounts</h3>
        <div class="settings-hint" style="margin-bottom:12px;">Passwords are stored as bcrypt hashes. Leave the password field empty to keep the current password.</div>

        <?php foreach (($sec['users'] ?? []) as $uname => $uhash): ?>
        <div class="settings-user-row">
            <label class="settings-check" style="flex-shrink:0;">
                <input type="checkbox" name="keep_user[]" value="<?= h($uname) ?>" checked>
            </label>
            <div class="settings-field" style="flex:1;">
                <input type="text" class="settings-input" value="<?= h($uname) ?>" disabled
                       style="opacity:0.7;">
            </div>
            <div class="settings-field" style="flex:1.5;">
                <input type="password" name="user_newpass_<?= h($uname) ?>" class="settings-input"
                       placeholder="New password (leave empty to keep)" autocomplete="new-password">
            </div>
        </div>
        <?php endforeach; ?>

        <div class="settings-divider"></div>
        <div class="settings-hint" style="margin-bottom:8px;">Add new user:</div>
        <div class="settings-user-row">
            <div class="settings-field" style="flex:1;">
                <input type="text" name="new_username" class="settings-input"
                       placeholder="Username (min 3 chars)" autocomplete="off">
            </div>
            <div class="settings-field" style="flex:1.5;">
                <input type="password" name="new_password" class="settings-input"
                       placeholder="Password (min 6 chars)" autocomplete="new-password">
            </div>
        </div>
    </div>

    <!-- Fonts -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('edit', 16) ?> Fonts</h3>
        <div class="settings-hint" style="margin-bottom:14px;">Customize fonts for different parts of the interface. Select "Theme default" to use the theme's built-in font. Google Fonts are loaded automatically.</div>

        <?php
        $fontZones = ledger_font_zones();
        $fontCatalog = ledger_font_catalog();
        $currentFonts = $config['app']['fonts'] ?? [];
        ?>

        <div class="font-grid">
            <?php foreach ($fontZones as $zoneKey => $zone): ?>
            <div class="font-zone">
                <label class="settings-label" for="font-<?= h($zoneKey) ?>"><?= h($zone['label']) ?></label>
                <select id="font-<?= h($zoneKey) ?>" name="font_<?= h($zoneKey) ?>" class="settings-input font-select"
                        data-zone="<?= h($zoneKey) ?>" data-catalog="<?= h($zone['catalog']) ?>">
                    <?php
                    $cat = $fontCatalog[$zone['catalog']] ?? [];
                    $currentVal = $currentFonts[$zoneKey] ?? '';
                    $hasGroups = true;
                    $inGoogle = false;
                    $inSystem = false;
                    foreach ($cat as $fontName => $fontInfo):
                        $isGoogle = !empty($fontInfo['google']);
                        // Group headers
                        if ($fontName === '' || ($isGoogle && !$inGoogle)):
                            if ($inSystem) echo '</optgroup>';
                            if ($fontName === ''):
                                // default option
                            elseif ($isGoogle && !$inGoogle):
                                echo '<optgroup label="Google Fonts">';
                                $inGoogle = true;
                            endif;
                        elseif (!$isGoogle && $inGoogle && !$inSystem):
                            echo '</optgroup><optgroup label="System Fonts">';
                            $inSystem = true;
                        endif;
                    ?>
                    <option value="<?= h($fontName) ?>" <?= $currentVal === $fontName ? 'selected' : '' ?>
                            <?= $fontName ? 'style="font-family: ' . h($fontName) . ';"' : '' ?>>
                        <?= h($fontInfo['label']) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php if ($inGoogle || $inSystem) echo '</optgroup>'; ?>
                </select>
                <div class="settings-hint"><?= h($zone['desc']) ?></div>
                <div class="font-preview" id="font-preview-<?= h($zoneKey) ?>"
                     data-catalog="<?= h($zone['catalog']) ?>">
                    The quick brown fox jumps over the lazy dog — 0123456789
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Access Control -->
    <div class="settings-section">
        <h3 class="section-title"><?= icon('filter', 16) ?> Access Control</h3>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label" for="s-ipwl">IP Whitelist</label>
                <textarea id="s-ipwl" name="ip_whitelist" class="settings-textarea" rows="3"
                          placeholder="One IP or CIDR per line (empty = allow all)"
                ><?= h(implode("\n", $sec['ip_whitelist'] ?? [])) ?></textarea>
                <div class="settings-hint">e.g. 192.168.1.0/24, 10.0.0.50</div>
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-proxies">Trusted Proxies</label>
                <textarea id="s-proxies" name="trusted_proxies" class="settings-textarea" rows="3"
                          placeholder="One proxy IP per line (empty = ignore forwarded headers)"
                ><?= h(implode("\n", $sec['trusted_proxies'] ?? [])) ?></textarea>
                <div class="settings-hint">X-Forwarded-For is only trusted from these IPs. Leave empty unless Ledger sits behind a reverse proxy.</div>
            </div>
            <div class="settings-field">
                <label class="settings-label" for="s-hiddendb">Hidden Databases</label>
                <textarea id="s-hiddendb" name="hidden_databases" class="settings-textarea" rows="3"
                          placeholder="One database name per line"
                ><?= h(implode("\n", $sec['hidden_databases'] ?? [])) ?></textarea>
                <div class="settings-hint">These won't appear in sidebar or autocomplete</div>
            </div>
        </div>
    </div>

    <!-- Save -->
    <div class="settings-footer">
        <button type="submit" class="btn btn-primary">
            <?= icon('check', 14) ?> Save Settings
        </button>
        <span class="settings-hint">Changes are written to config.php</span>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var loadedFonts = {};

    function loadGoogleFont(fontName, weights) {
        if (!fontName || loadedFonts[fontName]) return;
        loadedFonts[fontName] = true;
        var family = fontName.replace(/ /g, '+') + ':wght@' + (weights || '400;700');
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + family + '&display=swap';
        document.head.appendChild(link);
    }

    // Font catalog (mirrors PHP)
    var googleFonts = {
        'DM Sans':1,'Inter':1,'Nunito Sans':1,'Open Sans':1,'Lato':1,'Roboto':1,
        'Source Sans 3':1,'Outfit':1,'Sora':1,'Work Sans':1,'Poppins':1,'IBM Plex Sans':1,
        'JetBrains Mono':1,'Fira Code':1,'Source Code Pro':1,'IBM Plex Mono':1,
        'Roboto Mono':1,'Inconsolata':1,'Space Mono':1,'Ubuntu Mono':1
    };

    document.querySelectorAll('.font-select').forEach(function(sel) {
        var zone = sel.dataset.zone;
        var catalog = sel.dataset.catalog;
        var preview = document.getElementById('font-preview-' + zone);

        function update() {
            var fontName = sel.value;
            if (!fontName) {
                preview.style.fontFamily = catalog === 'mono' ? 'var(--font-mono)' : 'var(--font-body)';
                return;
            }
            // Load if Google Font
            if (googleFonts[fontName]) loadGoogleFont(fontName, '400;600;700');

            var fallback = catalog === 'mono' ? ', monospace' : ', system-ui, sans-serif';
            preview.style.fontFamily = "'" + fontName + "'" + fallback;
        }

        sel.addEventListener('change', update);
        update(); // Initial
    });
});
</script>
