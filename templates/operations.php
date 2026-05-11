<?php if (!$currentDb || !$currentTable): ?>
<div class="error-box">Select a database and table to view operations.</div>
<?php return; endif; ?>

<?php
$isReadOnly = isset($auth) && $auth->isReadOnly();
$tableStatus = null;
$engines = [];
$collations = [];
try {
    $tableStatus = $dbInstance->getTableStatus($currentDb, $currentTable);
    $engines = $dbInstance->getEngines();
    $collations = $dbInstance->getCollations();
} catch (Exception $e) {}

$currentEngine    = $tableStatus['Engine']     ?? '';
$currentCollation = $tableStatus['Collation']  ?? '';
$currentRowFormat = $tableStatus['Row_format'] ?? '';
$currentComment   = $tableStatus['Comment']    ?? '';
$rowFormats = ['DYNAMIC', 'COMPACT', 'REDUNDANT', 'COMPRESSED', 'FIXED'];

// Other databases (for move/copy targets)
$otherDatabases = array_filter($databases, fn($d) => $d !== $currentDb);
?>

<!-- Header -->
<div class="info-header info-header-red">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('settings', 24) ?></div>
        <div>
            <h3 class="info-header-title">Operations</h3>
            <span class="info-header-sub"><?= h($currentDb) ?> · <?= h($currentTable) ?></span>
        </div>
    </div>
</div>

<?php if ($isReadOnly): ?>
<div class="error-box" style="margin-bottom:16px;">
    <strong>Read-only mode is enabled.</strong> All operations are disabled.
</div>
<?php endif; ?>

<!-- ── Alter Table ── -->
<div class="panel-section" style="margin-bottom:16px;">
    <div class="panel-section-header"><?= icon('edit', 14) ?> Alter Table</div>
    <div class="panel-section-body">
        <div class="ops-grid-2">
            <div class="settings-field">
                <label class="settings-label">Engine</label>
                <select id="op-engine" class="settings-input" <?= $isReadOnly ? 'disabled' : '' ?>>
                    <?php foreach ($engines as $e): ?>
                    <option value="<?= h($e) ?>" <?= $e === $currentEngine ? 'selected' : '' ?>><?= h($e) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-field">
                <label class="settings-label">Row Format</label>
                <select id="op-row-format" class="settings-input" <?= $isReadOnly ? 'disabled' : '' ?>>
                    <?php foreach ($rowFormats as $rf): ?>
                    <option value="<?= h($rf) ?>" <?= strcasecmp($rf, $currentRowFormat) === 0 ? 'selected' : '' ?>><?= h($rf) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="settings-field" style="margin-top:10px;">
            <label class="settings-label">Collation</label>
            <select id="op-collation" class="settings-input" <?= $isReadOnly ? 'disabled' : '' ?>>
                <?php foreach ($collations as $c): ?>
                <option value="<?= h($c) ?>" <?= $c === $currentCollation ? 'selected' : '' ?>><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-hint">Changing collation rebuilds the table.</div>
        </div>
        <div class="settings-field" style="margin-top:10px;">
            <label class="settings-label">Comment</label>
            <input type="text" id="op-comment" class="settings-input" value="<?= h($currentComment) ?>" maxlength="2048" <?= $isReadOnly ? 'disabled' : '' ?>>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-primary" id="op-alter-btn" <?= $isReadOnly ? 'disabled' : '' ?>>
                <?= icon('check', 13) ?> Save changes
            </button>
        </div>
    </div>
</div>

<!-- ── Copy / Move ── -->
<div class="ops-grid-2" style="margin-bottom:16px;">
    <!-- Rename -->
    <div class="panel-section">
        <div class="panel-section-header"><?= icon('edit', 14) ?> Rename</div>
        <div class="panel-section-body">
            <div class="settings-field">
                <label class="settings-label">New name</label>
                <input type="text" id="op-rename-name" class="settings-input" value="<?= h($currentTable) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
            </div>
            <div style="margin-top:14px;display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-primary btn-sm" id="op-rename-btn" <?= $isReadOnly ? 'disabled' : '' ?>>Rename</button>
            </div>
        </div>
    </div>

    <!-- Move -->
    <div class="panel-section">
        <div class="panel-section-header"><?= icon('share', 14) ?> Move to Database</div>
        <div class="panel-section-body">
            <div class="settings-field">
                <label class="settings-label">Target database</label>
                <select id="op-move-db" class="settings-input" <?= $isReadOnly || empty($otherDatabases) ? 'disabled' : '' ?>>
                    <?php if (empty($otherDatabases)): ?>
                    <option value="">— No other databases —</option>
                    <?php else: ?>
                    <option value="">— Select database —</option>
                    <?php foreach ($otherDatabases as $d): ?>
                    <option value="<?= h($d) ?>"><?= h($d) ?></option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div style="margin-top:14px;display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-primary btn-sm" id="op-move-btn" <?= $isReadOnly || empty($otherDatabases) ? 'disabled' : '' ?>>Move</button>
            </div>
        </div>
    </div>
</div>

<!-- Copy -->
<div class="panel-section" style="margin-bottom:16px;">
    <div class="panel-section-header"><?= icon('copy', 14) ?> Copy Table</div>
    <div class="panel-section-body">
        <div class="ops-grid-2">
            <div class="settings-field">
                <label class="settings-label">New table name</label>
                <input type="text" id="op-copy-name" class="settings-input" value="<?= h($currentTable) ?>_copy" <?= $isReadOnly ? 'disabled' : '' ?>>
            </div>
            <div class="settings-field">
                <label class="settings-label">Target database</label>
                <select id="op-copy-db" class="settings-input" <?= $isReadOnly ? 'disabled' : '' ?>>
                    <option value=""><?= h($currentDb) ?> (same database)</option>
                    <?php foreach ($otherDatabases as $d): ?>
                    <option value="<?= h($d) ?>"><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="margin-top:12px;display:flex;gap:18px;align-items:center;">
            <label class="settings-check" style="cursor:pointer;">
                <input type="radio" name="op-copy-mode" value="data" checked> Structure + data
            </label>
            <label class="settings-check" style="cursor:pointer;">
                <input type="radio" name="op-copy-mode" value="structure"> Structure only
            </label>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-primary btn-sm" id="op-copy-btn" <?= $isReadOnly ? 'disabled' : '' ?>>Copy</button>
        </div>
    </div>
</div>

<!-- ── Maintenance ── -->
<div class="panel-section" style="margin-bottom:16px;">
    <div class="panel-section-header"><?= icon('zap', 14) ?> Maintenance</div>
    <div class="panel-section-body">
        <div class="ops-maintenance">
            <div class="ops-maint-row">
                <div class="ops-maint-info">
                    <div class="ops-maint-title">Optimize table</div>
                    <div class="ops-maint-desc">Rebuild the table to reclaim unused space and defragment data and index pages.</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm ops-maint-btn" data-op="optimize_table">Run</button>
            </div>
            <div class="ops-maint-row">
                <div class="ops-maint-info">
                    <div class="ops-maint-title">Analyze table</div>
                    <div class="ops-maint-desc">Update key distribution statistics so the optimizer makes better query plans.</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm ops-maint-btn" data-op="analyze_table">Run</button>
            </div>
            <div class="ops-maint-row">
                <div class="ops-maint-info">
                    <div class="ops-maint-title">Check table</div>
                    <div class="ops-maint-desc">Look for errors. Read-only — does not modify the table.</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm ops-maint-btn" data-op="check_table">Run</button>
            </div>
            <div class="ops-maint-row">
                <div class="ops-maint-info">
                    <div class="ops-maint-title">Repair table</div>
                    <div class="ops-maint-desc">Attempt to repair a corrupted table. MyISAM/ARIA only — InnoDB has no equivalent operation.</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm ops-maint-btn" data-op="repair_table" <?= $isReadOnly ? 'disabled' : '' ?>>Run</button>
            </div>
        </div>
        <div id="ops-maint-result" class="ops-maint-result" style="display:none;"></div>
    </div>
</div>

<!-- ── Danger Zone ── -->
<div class="panel-section danger-section" style="margin-bottom:16px;">
    <div class="panel-section-header" style="color:var(--danger);"><?= icon('alert-triangle', 14) ?> Danger Zone</div>
    <div class="panel-section-body">
        <div class="ops-danger-row">
            <div class="ops-maint-info">
                <div class="ops-maint-title">Truncate table</div>
                <div class="ops-maint-desc">Delete every row in <code><?= h($currentTable) ?></code>. The structure is kept, AUTO_INCREMENT resets.</div>
            </div>
            <button type="button" class="btn btn-danger btn-sm" id="op-truncate-btn" <?= $isReadOnly ? 'disabled' : '' ?>>Truncate</button>
        </div>
        <div class="ops-danger-row">
            <div class="ops-maint-info">
                <div class="ops-maint-title">Drop table</div>
                <div class="ops-maint-desc">Permanently delete <code><?= h($currentTable) ?></code> and all its data. This cannot be undone.</div>
            </div>
            <button type="button" class="btn btn-danger btn-sm" id="op-drop-btn" <?= $isReadOnly ? 'disabled' : '' ?>>Drop</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = <?= json_encode($currentDb) ?>;
    var table = <?= json_encode($currentTable) ?>;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    var resultEl = document.getElementById('ops-maint-result');

    function post(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('db', db);
        fd.append('_csrf_token', csrf);
        Object.keys(params || {}).forEach(function(k) {
            if (params[k] !== null && params[k] !== undefined) fd.append(k, params[k]);
        });
        return fetch('ajax.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
    }

    function showResult(html, isError) {
        resultEl.innerHTML = html;
        resultEl.style.display = '';
        resultEl.classList.toggle('is-error', !!isError);
    }

    // Alter
    var alterBtn = document.getElementById('op-alter-btn');
    if (alterBtn) {
        alterBtn.addEventListener('click', function() {
            alterBtn.disabled = true;
            post('alter_table_options', {
                table: table,
                engine: document.getElementById('op-engine').value,
                row_format: document.getElementById('op-row-format').value,
                collation: document.getElementById('op-collation').value,
                comment: document.getElementById('op-comment').value,
            }).then(function(data) {
                alterBtn.disabled = false;
                if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                Ledger.setStatus('Table altered successfully.');
                setTimeout(function() { window.location.reload(); }, 600);
            });
        });
    }

    // Rename
    document.getElementById('op-rename-btn').addEventListener('click', function() {
        var newName = document.getElementById('op-rename-name').value.trim();
        if (!newName || newName === table) return;
        post('rename_table', { old_name: table, new_name: newName }).then(function(data) {
            if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
            Ledger.setStatus('Renamed to ' + newName + '.');
            window.location.href = '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(newName) + '&tab=operations';
        });
    });

    // Move
    document.getElementById('op-move-btn').addEventListener('click', function() {
        var target = document.getElementById('op-move-db').value;
        if (!target) return;
        Ledger.confirm({
            title: 'Move table',
            message: 'Move ' + table + ' from ' + db + ' to ' + target + '? FK references and triggers may break if other tables reference this one.',
            confirmText: 'Move',
            danger: true,
        }).then(function(ok) {
            if (!ok) return;
            post('move_table', { table: table, target_db: target }).then(function(data) {
                if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                Ledger.setStatus('Moved to ' + target + '.');
                window.location.href = '?db=' + encodeURIComponent(target) + '&table=' + encodeURIComponent(table) + '&tab=operations';
            });
        });
    });

    // Copy
    document.getElementById('op-copy-btn').addEventListener('click', function() {
        var dest = document.getElementById('op-copy-name').value.trim();
        var destDb = document.getElementById('op-copy-db').value;
        if (!dest) return;
        var withData = document.querySelector('input[name="op-copy-mode"]:checked').value === 'data';
        var params = { source: table, destination: dest, dest_db: destDb || '' };
        if (withData) params.with_data = '1';
        post('copy_table', params).then(function(data) {
            if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
            Ledger.setStatus('Copied to ' + (data.dest_db || db) + '.' + dest + '.');
            window.location.href = '?db=' + encodeURIComponent(data.dest_db || db) + '&table=' + encodeURIComponent(dest) + '&tab=browse';
        });
    });

    // Maintenance
    document.querySelectorAll('.ops-maint-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var op = btn.dataset.op;
            btn.disabled = true;
            var origText = btn.textContent;
            btn.textContent = 'Running…';
            post(op, { table: table }).then(function(data) {
                btn.disabled = false;
                btn.textContent = origText;
                if (data.error) {
                    showResult('<strong>' + op.replace('_', ' ') + ' failed:</strong> ' + data.error, true);
                    return;
                }
                showResult('<strong>' + op.replace('_', ' ') + ':</strong> ' + (data.result || 'OK').replace(/\n/g, '<br>'), false);
            });
        });
    });

    // Truncate
    document.getElementById('op-truncate-btn').addEventListener('click', function() {
        Ledger.confirm({
            title: 'Truncate table',
            message: 'Delete every row in `' + table + '`? Structure is preserved but all data is lost. AUTO_INCREMENT resets to 1.',
            confirmText: 'Truncate',
            danger: true,
        }).then(function(ok) {
            if (!ok) return;
            post('truncate_table', { table: table }).then(function(data) {
                if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                Ledger.setStatus('Table truncated.');
                setTimeout(function() { window.location.reload(); }, 500);
            });
        });
    });

    // Drop
    document.getElementById('op-drop-btn').addEventListener('click', function() {
        Ledger.confirm({
            title: 'Drop table',
            message: 'Permanently delete `' + table + '` and all its data? This cannot be undone.',
            confirmText: 'Drop',
            danger: true,
        }).then(function(ok) {
            if (!ok) return;
            post('drop_table', { name: table }).then(function(data) {
                if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                Ledger.setStatus('Table dropped.');
                window.location.href = '?db=' + encodeURIComponent(db);
            });
        });
    });
});
</script>
