<?php
$sqlInput = '';
$queryResult = null;
$maxHistory = (int)($config['app']['max_query_history'] ?? 50);

// POST submission takes priority (user edited and pressed execute)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql'])) {
    // Validate CSRF
    if (isset($auth) && $auth->csrfEnabled() && !$auth->validateCsrf()) {
        $queryResult = ['success' => false, 'error' => 'Invalid security token. Please reload the page.', 'code' => '', 'time' => 0];
    } else {
        $sqlInput = $_POST['sql'];
        if (!empty($sqlInput)) {
            // Read-only enforcement
            if (isset($auth) && $auth->isReadOnly() && $auth->isWriteQuery($sqlInput)) {
                $queryResult = ['success' => false, 'error' => 'Write operations are disabled in read-only mode.', 'code' => '', 'time' => 0];
            } else {
                $queryResult = $dbInstance->executeQuery($currentDb ?? 'mysql', $sqlInput);
                if (isset($auth)) {
                    $auth->logQuery($currentDb ?? 'mysql', $sqlInput, $queryResult['time'] ?? 0);
                }
                // Record to history
                ledger_record_history($sqlInput, $currentDb, $queryResult, $maxHistory);
            }
        }
    }
}
// GET with run=1 → auto-execute (from quick query links)
elseif (!empty($_GET['sql'])) {
    $sqlInput = $_GET['sql'];
    if (($_GET['run'] ?? '') === '1') {
        if (isset($auth) && $auth->isReadOnly() && $auth->isWriteQuery($sqlInput)) {
            $queryResult = ['success' => false, 'error' => 'Write operations are disabled in read-only mode.', 'code' => '', 'time' => 0];
        } else {
            $queryResult = $dbInstance->executeQuery($currentDb ?? 'mysql', $sqlInput);
            if (isset($auth)) {
                $auth->logQuery($currentDb ?? 'mysql', $sqlInput, $queryResult['time'] ?? 0);
            }
            ledger_record_history($sqlInput, $currentDb, $queryResult, $maxHistory);
        }
    }
}

// History helper
function ledger_record_history(string $sql, ?string $db, array $result, int $max): void
{
    if (!isset($_SESSION['ledger_query_history'])) {
        $_SESSION['ledger_query_history'] = [];
    }

    // Build a result summary string based on what executeQuery returned
    $success = (bool)($result['success'] ?? false);
    $error = $success ? null : ($result['error'] ?? 'Unknown error');
    $resultType = $result['type'] ?? null;
    $affected = $result['affected'] ?? null;
    $count = $result['count'] ?? null;

    // Rows value used by the row count badge: prefer SELECT count, fall back to affected
    $rowsForBadge = $count ?? $affected ?? 0;

    // Don't duplicate the exact same query at position 0
    if (!empty($_SESSION['ledger_query_history']) && $_SESSION['ledger_query_history'][0]['sql'] === $sql) {
        // Update the existing entry
        $_SESSION['ledger_query_history'][0]['timestamp'] = time();
        $_SESSION['ledger_query_history'][0]['db'] = $db;
        $_SESSION['ledger_query_history'][0]['success'] = $success;
        $_SESSION['ledger_query_history'][0]['time'] = $result['time'] ?? 0;
        $_SESSION['ledger_query_history'][0]['rows'] = $rowsForBadge;
        $_SESSION['ledger_query_history'][0]['result_type'] = $resultType;
        $_SESSION['ledger_query_history'][0]['affected'] = $affected;
        $_SESSION['ledger_query_history'][0]['count'] = $count;
        $_SESSION['ledger_query_history'][0]['error'] = $error;
        return;
    }

    array_unshift($_SESSION['ledger_query_history'], [
        'sql'         => $sql,
        'db'          => $db,
        'success'     => $success,
        'time'        => $result['time'] ?? 0,
        'rows'        => $rowsForBadge,
        'result_type' => $resultType,
        'affected'    => $affected,
        'count'       => $count,
        'error'       => $error,
        'timestamp'   => time(),
    ]);

    // Trim to max
    $_SESSION['ledger_query_history'] = array_slice($_SESSION['ledger_query_history'], 0, $max);
}

$queryHistory = $_SESSION['ledger_query_history'] ?? [];
?>

<!-- Clean URL after auto-run so GET param doesn't interfere with future edits -->
<script>
if (window.location.search.includes('sql=')) {
    var clean = window.location.pathname + '?db=' + encodeURIComponent('<?= addslashes($currentDb ?? '') ?>') + '&tab=sql'
        <?php if ($currentTable): ?> + '&table=' + encodeURIComponent('<?= addslashes($currentTable) ?>') <?php endif; ?>;
    history.replaceState(null, '', clean);
}
</script>

<!-- SQL Header -->
<div class="sql-header">
    <span class="sql-db-label">
        <?= icon('database', 14) ?> <?= h($currentDb ?? 'No database selected') ?>
    </span>
    <span style="font-size:var(--font-size-xs);color:var(--text-muted);">Ctrl+Enter to execute</span>
</div>

<!-- Editor — always POSTs, never carries GET sql param -->
<form method="post" id="sql-form" action="?db=<?= urlencode($currentDb ?? '') ?>&tab=sql<?= $currentTable ? '&table=' . urlencode($currentTable) : '' ?>">
    <?php if (isset($auth)): ?><?= $auth->csrfField() ?><?php endif; ?>
    <input type="hidden" name="db" value="<?= h($currentDb ?? '') ?>">
    <input type="hidden" name="tab" value="sql">
    <?php if ($currentTable): ?>
    <input type="hidden" name="table" value="<?= h($currentTable) ?>">
    <?php endif; ?>
    <div class="editor-wrap">
        <div class="editor-container" id="editor-container">
            <!-- Highlighted backdrop -->
            <pre class="editor-backdrop" id="editor-backdrop" aria-hidden="true"><code id="editor-highlight"></code></pre>
            <!-- Actual textarea (transparent text, visible caret) -->
            <textarea
                name="sql"
                class="sql-editor"
                id="sql-editor"
                spellcheck="false"
                rows="8"
                placeholder="SELECT * FROM users WHERE ..."
            ><?= h($sqlInput) ?></textarea>
        </div>
        <div class="editor-footer">
            <div class="editor-hints">
                <span class="editor-hint"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> Execute</span>
                <span class="editor-hint"><kbd>Tab</kbd> Indent</span>
                <span class="editor-hint"><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd> Focus editor</span>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-ghost" id="save-query-btn" title="Save this query">
                    <?= icon('check', 13) ?> Save
                </button>
                <button type="button" class="btn btn-ghost" id="explain-btn" title="EXPLAIN the current query">
                    <?= icon('activity', 13) ?> Explain
                </button>
                <button type="submit" class="btn btn-primary" id="run-btn">
                    <?= icon('play', 13) ?> Execute
                </button>
            </div>
        </div>
        <!-- Line numbers -->
        <div class="editor-line-numbers" id="editor-line-numbers" aria-hidden="true"></div>
    </div>
</form>

<!-- EXPLAIN Panel -->
<div class="explain-panel" id="explain-panel" style="display:none;">
    <div class="explain-header">
        <span class="explain-title"><?= icon('activity', 13) ?> Query Plan</span>
        <span class="explain-meta" id="explain-meta"></span>
        <button type="button" class="explain-close" id="explain-close" title="Close">&times;</button>
    </div>
    <div class="explain-body" id="explain-body">
        <!-- filled by JS -->
    </div>
</div>

<!-- Query History -->
<?php if (!empty($queryHistory)): ?>
<div class="history-panel" id="history-panel">
    <div class="history-header" id="history-toggle">
        <?= icon('clock', 14) ?>
        <span>Query History</span>
        <span class="history-count"><?= count($queryHistory) ?></span>
        <div class="history-header-actions">
            <input type="text" class="history-search" id="history-search" placeholder="Filter…" onclick="event.stopPropagation();">
            <button type="button" class="btn btn-ghost btn-sm history-clear-btn" id="history-clear" onclick="event.stopPropagation();" title="Clear history">
                <?= icon('trash', 11) ?>
            </button>
            <span class="history-chevron" id="history-chevron"><?= icon('chevron-down', 12) ?></span>
        </div>
    </div>
    <div class="history-list" id="history-list" style="display:none;">
        <?php foreach ($queryHistory as $i => $hq):
            // Build the result summary shown under the SQL
            $resultSummary = null;
            if ($hq['success']) {
                if (($hq['result_type'] ?? null) === 'select') {
                    $c = (int)($hq['count'] ?? 0);
                    $resultSummary = $c === 1 ? '1 row returned' : format_number($c) . ' rows returned';
                } elseif (($hq['result_type'] ?? null) === 'modify') {
                    $a = (int)($hq['affected'] ?? 0);
                    $resultSummary = $a === 1 ? '1 row affected' : format_number($a) . ' rows affected';
                }
            }
            $hasDetail = !$hq['success'] ? !empty($hq['error']) : !empty($resultSummary);
        ?>
        <div class="history-item <?= $hq['success'] ? 'history-item-ok' : 'history-item-err' ?>" data-sql="<?= h($hq['sql']) ?>" data-idx="<?= $i ?>">
            <span class="history-item-status"><?= $hq['success'] ? icon('check', 10) : icon('x', 10) ?></span>
            <div class="history-item-body">
                <code class="history-item-sql" id="hq-<?= $i ?>"><?= h(truncate($hq['sql'], 150)) ?></code>
                <?php if ($hasDetail): ?>
                <div class="history-item-detail" style="display:none;">
                    <?php if (!$hq['success'] && !empty($hq['error'])): ?>
                    <div class="history-item-error"><?= h($hq['error']) ?></div>
                    <?php elseif ($resultSummary): ?>
                    <div class="history-item-result"><?= h($resultSummary) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="history-item-meta">
                    <?php if ($hq['db']): ?>
                    <span class="history-item-db"><?= icon('database', 9) ?> <?= h($hq['db']) ?></span>
                    <?php endif; ?>
                    <span><?= number_format($hq['time'], 3) ?>s</span>
                    <span class="history-item-time" title="<?= date('Y-m-d H:i:s', $hq['timestamp']) ?>">
                        <?= ledger_time_ago($hq['timestamp']) ?>
                    </span>
                </div>
            </div>
            <div class="history-item-actions">
                <button type="button" class="history-load-btn" data-idx="<?= $i ?>" title="Load into editor"><?= icon('edit', 11) ?></button>
                <button type="button" class="history-delete-btn" data-idx="<?= $i ?>" title="Remove"><?= icon('x', 10) ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('history-toggle');
    var list = document.getElementById('history-list');
    var chevron = document.getElementById('history-chevron');
    var search = document.getElementById('history-search');
    var clearBtn = document.getElementById('history-clear');

    if (!toggle || !list) return;

    // Toggle open/close
    toggle.addEventListener('click', function() {
        var open = list.style.display !== 'none';
        list.style.display = open ? 'none' : '';
        chevron.style.transform = open ? '' : 'rotate(180deg)';
    });

    // Syntax-highlight history SQL
    if (typeof Ledger !== 'undefined' && Ledger.tokenize) {
        list.querySelectorAll('.history-item-sql').forEach(function(el) {
            var tokens = Ledger.tokenize(el.textContent);
            tokens = Ledger.resolveTableNames(tokens);
            el.innerHTML = Ledger.renderTokens(tokens);
        });
    }

    // Click history item → toggle expanded detail (ignore action button clicks)
    list.addEventListener('click', function(e) {
        if (e.target.closest('.history-delete-btn')) return;
        if (e.target.closest('.history-load-btn')) return;
        var item = e.target.closest('.history-item');
        if (!item) return;
        var detail = item.querySelector('.history-item-detail');
        if (!detail) return; // nothing to show
        var isOpen = detail.style.display !== 'none';
        detail.style.display = isOpen ? 'none' : '';
        item.classList.toggle('history-item-expanded', !isOpen);
    });

    // Load button → paste SQL into editor
    list.addEventListener('click', function(e) {
        var btn = e.target.closest('.history-load-btn');
        if (!btn) return;
        e.stopPropagation();
        var item = btn.closest('.history-item');
        if (!item) return;
        var sql = item.dataset.sql;
        var editor = document.getElementById('sql-editor');
        if (editor && sql) {
            editor.value = sql;
            editor.dispatchEvent(new Event('input'));
            editor.focus();
            editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // Delete individual history item
    list.addEventListener('click', function(e) {
        var btn = e.target.closest('.history-delete-btn');
        if (!btn) return;
        var idx = parseInt(btn.dataset.idx);
        var item = btn.closest('.history-item');

        var fd = new FormData();
        fd.append('action', 'delete_history_item');
        fd.append('idx', idx);
        fd.append('_csrf_token', Ledger.getCsrfToken());
        fetch('ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    item.style.transition = 'opacity 0.15s, height 0.15s, padding 0.15s';
                    item.style.opacity = '0';
                    item.style.height = '0';
                    item.style.padding = '0 16px';
                    item.style.overflow = 'hidden';
                    setTimeout(function() { item.remove(); }, 160);

                    // Update count badge
                    var countEl = document.querySelector('.history-count');
                    if (countEl) {
                        var remaining = list.querySelectorAll('.history-item').length - 1;
                        countEl.textContent = remaining;
                        if (remaining === 0) {
                            document.getElementById('history-panel').remove();
                        }
                    }
                }
            });
    });

    // Filter
    if (search) {
        search.addEventListener('input', function() {
            var q = search.value.toLowerCase();
            list.querySelectorAll('.history-item').forEach(function(item) {
                var sql = item.dataset.sql.toLowerCase();
                item.style.display = (!q || sql.includes(q)) ? '' : 'none';
            });
        });
    }

    // Clear history
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (typeof Ledger !== 'undefined' && Ledger.confirm) {
                Ledger.confirm({
                    title: 'Clear Query History',
                    message: 'This will remove all queries from your session history. This cannot be undone.',
                    confirmText: 'Clear',
                    cancelText: 'Cancel',
                    danger: true,
                }).then(function(ok) {
                    if (!ok) return;
                    doClear();
                });
            } else {
                doClear();
            }
        });

        function doClear() {
            var fd = new FormData();
            fd.append('action', 'clear_history');
            fd.append('_csrf_token', Ledger.getCsrfToken());
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('history-panel').remove();
                        Ledger.setStatus('Query history cleared.');
                    }
                });
        }
    }
});
</script>

<!-- Results -->
<?php if ($queryResult !== null): ?>
<div style="margin-top:16px;">
    <?php if (!$queryResult['success']): ?>
        <div class="error-box">
            <strong>ERROR <?= h($queryResult['code'] ?? '') ?>:</strong> <?= h($queryResult['error']) ?>
            <span style="float:right;color:var(--text-muted);"><?= $queryResult['time'] ?>s</span>
        </div>
    <?php elseif ($queryResult['type'] === 'select'): ?>
        <div class="result-meta">
            <?= format_number($queryResult['count']) ?> rows returned in <?= $queryResult['time'] ?>s
        </div>
        <?php if (!empty($queryResult['rows'])): ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php foreach ($queryResult['columns'] as $col): ?>
                        <th><?= h($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queryResult['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($queryResult['columns'] as $col): ?>
                        <td>
                            <?php if ($row[$col] === null): ?>
                            <span class="cell-null">NULL</span>
                            <?php else: ?>
                            <?= h(truncate(strval($row[$col]), 100)) ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="color:var(--text-muted);padding:20px;text-align:center;">Empty result set</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="success-box">
            Query executed successfully. <?= format_number($queryResult['affected']) ?> row(s) affected in <?= $queryResult['time'] ?>s
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Quick Queries -->
<?php if ($currentTable): ?>
<div style="margin-top:24px;">
    <h4 class="section-title" style="font-size:var(--font-size-sm);">Quick Queries</h4>
    <div class="quick-queries">
        <?php
        $quickQueries = [
            "SELECT * FROM `{$currentTable}`",
            "SELECT * FROM `{$currentTable}` LIMIT 25",
            "SELECT COUNT(*) FROM `{$currentTable}`",
            "DESCRIBE `{$currentTable}`",
            "SHOW INDEX FROM `{$currentTable}`",
            "SHOW CREATE TABLE `{$currentTable}`",
        ];
        foreach ($quickQueries as $q):
        ?>
        <a href="#" class="quick-query" onclick="Ledger.loadAndRun(this.dataset.sql);return false;" data-sql="<?= h($q) ?>">
            <code><?= h($q) ?></code>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Saved Queries Panel -->
<div style="margin-top:24px;" id="saved-queries-section">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:8px 0;" id="saved-queries-toggle">
        <h4 class="section-title" style="font-size:var(--font-size-sm);margin:0;">
            <?= icon('database', 13) ?> Saved Queries
            <span id="saved-queries-count" style="font-size:var(--font-size-xs);color:var(--text-muted);font-weight:400;margin-left:6px;"></span>
        </h4>
        <span style="font-size:var(--font-size-xs);color:var(--text-muted);" id="saved-queries-chevron">▼</span>
    </div>
    <div id="saved-queries-list" style="display:none;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = <?= json_encode($currentDb ?? '') ?>;
    var editor = document.getElementById('sql-editor');
    var listEl = document.getElementById('saved-queries-list');
    var countEl = document.getElementById('saved-queries-count');
    var chevron = document.getElementById('saved-queries-chevron');
    var toggle = document.getElementById('saved-queries-toggle');
    var saveBtn = document.getElementById('save-query-btn');
    var isOpen = false;

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Toggle panel
    toggle.addEventListener('click', function() {
        isOpen = !isOpen;
        listEl.style.display = isOpen ? '' : 'none';
        chevron.textContent = isOpen ? '▲' : '▼';
        if (isOpen) loadSaved();
    });

    // Load saved queries
    function loadSaved() {
        fetch('ajax.php?action=get_saved_queries&db=' + encodeURIComponent(db))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var queries = data.queries || [];
                countEl.textContent = queries.length ? '(' + queries.length + ')' : '';
                if (!queries.length) {
                    listEl.innerHTML = '<div style="padding:12px;font-size:var(--font-size-xs);color:var(--text-muted);text-align:center;">No saved queries yet. Use the Save button to save the current query.</div>';
                    return;
                }
                var html = '';
                queries.forEach(function(q) {
                    var shortSql = q.sql.length > 120 ? q.sql.substring(0, 117) + '…' : q.sql;
                    var ago = timeAgo(q.updated || q.created);
                    var dbBadge = q.db ? '<span style="font-size:9px;padding:1px 6px;border-radius:3px;background:var(--accent-bg);color:var(--accent);font-family:var(--font-mono);">' + escHtml(q.db) + '</span>' : '';
                    html += '<div class="saved-query-item" data-id="' + escHtml(q.id) + '" style="padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:6px;cursor:pointer;transition:border-color 0.15s,background 0.15s;">'
                        + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">'
                        + '<span style="font-weight:600;font-size:var(--font-size-sm);color:var(--text-primary);flex:1;" class="sq-name">' + escHtml(q.name) + '</span>'
                        + dbBadge
                        + '<span style="font-size:var(--font-size-xs);color:var(--text-muted);">' + ago + '</span>'
                        + '<button type="button" class="sq-rename" title="Rename" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:12px;padding:2px 4px;">✏️</button>'
                        + '<button type="button" class="sq-delete" title="Delete" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:12px;padding:2px 4px;">🗑️</button>'
                        + '</div>'
                        + '<code style="font-size:var(--font-size-xs);color:var(--text-secondary);font-family:var(--font-mono);line-height:1.5;word-break:break-all;">' + escHtml(shortSql) + '</code>'
                        + '</div>';
                });
                listEl.innerHTML = html;

                // Bind events
                listEl.querySelectorAll('.saved-query-item').forEach(function(item, idx) {
                    var q = queries[idx];
                    // Click to load
                    item.addEventListener('click', function(e) {
                        if (e.target.closest('.sq-rename') || e.target.closest('.sq-delete')) return;
                        if (editor) {
                            editor.value = q.sql;
                            editor.dispatchEvent(new Event('input'));
                            editor.focus();
                            editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            Ledger.setStatus('Loaded: ' + q.name);
                        }
                    });
                    item.addEventListener('mouseenter', function() { this.style.borderColor = 'var(--accent)'; this.style.background = 'var(--bg-hover)'; });
                    item.addEventListener('mouseleave', function() { this.style.borderColor = 'var(--border)'; this.style.background = ''; });

                    // Rename — inline edit
                    item.querySelector('.sq-rename').addEventListener('click', function(e) {
                        e.stopPropagation();
                        var nameEl = item.querySelector('.sq-name');
                        var oldName = q.name;
                        var inp = document.createElement('input');
                        inp.type = 'text';
                        inp.value = oldName;
                        inp.className = 'settings-input';
                        inp.style.cssText = 'font-size:var(--font-size-sm);padding:3px 6px;width:100%;';
                        nameEl.innerHTML = '';
                        nameEl.appendChild(inp);
                        inp.focus();
                        inp.select();

                        function finishRename() {
                            var newName = inp.value.trim();
                            if (!newName || newName === oldName) { nameEl.textContent = oldName; return; }
                            nameEl.textContent = newName;
                            var fd = new FormData();
                            fd.append('action', 'update_saved_query');
                            fd.append('id', q.id);
                            fd.append('name', newName);
                            fd.append('_csrf_token', getCsrf());
                            fetch('ajax.php', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) { if (data.success) { q.name = newName; } else { nameEl.textContent = oldName; } });
                        }
                        inp.addEventListener('blur', finishRename);
                        inp.addEventListener('keydown', function(ev) { if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); } if (ev.key === 'Escape') { nameEl.textContent = oldName; } });
                    });

                    // Delete
                    item.querySelector('.sq-delete').addEventListener('click', function(e) {
                        e.stopPropagation();
                        // Use Ledger confirm if available, otherwise just delete
                        function doDelete() {
                            var fd = new FormData();
                            fd.append('action', 'delete_saved_query');
                            fd.append('id', q.id);
                            fd.append('_csrf_token', getCsrf());
                            fetch('ajax.php', { method: 'POST', body: fd })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) {
                                        item.style.opacity = '0';
                                        item.style.transform = 'translateX(-20px)';
                                        item.style.transition = 'all 0.2s';
                                        setTimeout(function() { loadSaved(); }, 200);
                                    }
                                });
                        }
                        if (typeof Ledger !== 'undefined' && Ledger.confirm) {
                            Ledger.confirm('Delete "' + q.name + '"?', 'This action cannot be undone.', doDelete);
                        } else {
                            doDelete();
                        }
                    });
                });
            });
    }

    // Save button
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            if (!editor) return;
            var sql = editor.value.trim();
            if (!sql) { if (typeof Ledger !== 'undefined') Ledger.setStatus('Nothing to save.'); return; }

            // Build inline save dialog
            var defaultName = sql.substring(0, 50).replace(/\s+/g, ' ');
            if (defaultName.length >= 50) defaultName = defaultName.substring(0, 47) + '…';

            // Remove existing dialog if any
            var existing = document.getElementById('save-query-dialog');
            if (existing) existing.remove();

            var overlay = document.createElement('div');
            overlay.id = 'save-query-dialog';
            overlay.className = 'modal-overlay';
            overlay.innerHTML =
                '<div class="modal-box" style="max-width:400px;">' +
                    '<div class="modal-header">' +
                        '<span class="modal-title">Save Query</span>' +
                        '<button class="modal-close" id="sq-cancel">&times;</button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                        '<div class="settings-field">' +
                            '<label class="settings-label">Query name</label>' +
                            '<input type="text" id="sq-name-input" class="settings-input" value="' + defaultName.replace(/"/g, '&quot;') + '" placeholder="My query" autofocus>' +
                        '</div>' +
                        '<div style="margin-top:8px;padding:8px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);max-height:80px;overflow:auto;">' +
                            '<code style="font-size:var(--font-size-xs);color:var(--text-secondary);font-family:var(--font-mono);word-break:break-all;">' + sql.substring(0, 200).replace(/</g, '&lt;') + (sql.length > 200 ? '…' : '') + '</code>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                        '<button class="btn btn-ghost modal-btn" id="sq-cancel2">Cancel</button>' +
                        '<button class="btn btn-primary modal-btn" id="sq-confirm">Save Query</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

            var nameInput = document.getElementById('sq-name-input');
            nameInput.focus();
            nameInput.select();

            function close() {
                overlay.classList.remove('modal-visible');
                setTimeout(function() { overlay.remove(); }, 150);
            }

            function doSave() {
                var name = nameInput.value.trim();
                if (!name) { nameInput.style.borderColor = 'var(--danger)'; nameInput.focus(); return; }
                close();

                saveBtn.disabled = true;
                var orig = saveBtn.innerHTML;
                saveBtn.textContent = 'Saving…';

                var fd = new FormData();
                fd.append('action', 'save_query');
                fd.append('name', name);
                fd.append('sql', sql);
                fd.append('db', db);
                fd.append('_csrf_token', getCsrf());
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        saveBtn.disabled = false;
                        if (data.success) {
                            saveBtn.innerHTML = '<?= icon('check', 13) ?> Saved!';
                            saveBtn.style.color = 'var(--accent)';
                            setTimeout(function() { saveBtn.innerHTML = orig; saveBtn.style.color = ''; }, 2000);
                            if (!isOpen) { isOpen = true; listEl.style.display = ''; chevron.textContent = '▲'; }
                            loadSaved();
                            if (typeof Ledger !== 'undefined') Ledger.setStatus('Query saved: ' + name);
                        } else {
                            saveBtn.innerHTML = orig;
                            if (typeof Ledger !== 'undefined') Ledger.setStatus(data.error || 'Save failed.');
                        }
                    });
            }

            document.getElementById('sq-confirm').addEventListener('click', doSave);
            document.getElementById('sq-cancel').addEventListener('click', close);
            document.getElementById('sq-cancel2').addEventListener('click', close);
            nameInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') doSave(); if (e.key === 'Escape') close(); });
            overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
        });
    }

    // Helpers
    function escHtml(s) {
        var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
    }
    function timeAgo(ts) {
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return new Date(ts * 1000).toLocaleDateString();
    }

    // Auto-load count on page load
    fetch('ajax.php?action=get_saved_queries&db=' + encodeURIComponent(db))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var n = (data.queries || []).length;
            countEl.textContent = n ? '(' + n + ')' : '';
        });
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById('explain-btn');
    var panel = document.getElementById('explain-panel');
    var body = document.getElementById('explain-body');
    var meta = document.getElementById('explain-meta');
    var closeBtn = document.getElementById('explain-close');
    if (!btn || !panel) return;

    // Efficiency classification for the `type` column (access method)
    var typeClass = {
        'system': 'good', 'const': 'good', 'eq_ref': 'good',
        'ref': 'ok', 'ref_or_null': 'ok', 'range': 'ok', 'fulltext': 'ok',
        'index_merge': 'ok', 'unique_subquery': 'ok', 'index_subquery': 'ok',
        'index': 'warn',
        'ALL': 'bad'
    };

    // Extra notes — substrings that indicate warnings
    var extraWarnings = [
        'Using filesort', 'Using temporary', 'Using where; Using filesort',
        'Range checked for each record', 'Using join buffer'
    ];
    var extraGood = ['Using index', 'Using index condition', 'Using where'];

    function classifyExtra(extra) {
        if (!extra) return '';
        for (var i = 0; i < extraWarnings.length; i++) {
            if (extra.indexOf(extraWarnings[i]) !== -1) return 'warn';
        }
        for (var i = 0; i < extraGood.length; i++) {
            if (extra.indexOf(extraGood[i]) !== -1) return 'good';
        }
        return '';
    }

    function formatNum(n) {
        if (n == null) return '—';
        var num = parseInt(n, 10);
        if (isNaN(num)) return String(n);
        return num.toLocaleString();
    }

    function rowsCellClass(n) {
        var num = parseInt(n, 10);
        if (isNaN(num)) return '';
        if (num > 100000) return 'explain-rows-bad';
        if (num > 10000)  return 'explain-rows-warn';
        return '';
    }

    function renderResults(data) {
        if (data.error) {
            body.innerHTML = '<div class="explain-error">' + escapeHtml(data.error) + '</div>';
            meta.textContent = '';
            return;
        }
        if (!data.rows || !data.rows.length) {
            body.innerHTML = '<div class="explain-empty">No plan returned.</div>';
            return;
        }

        var cols = Object.keys(data.rows[0]);
        var html = '<table class="explain-table"><thead><tr>';
        cols.forEach(function(c) { html += '<th>' + escapeHtml(c) + '</th>'; });
        html += '</tr></thead><tbody>';

        data.rows.forEach(function(row) {
            html += '<tr>';
            cols.forEach(function(col) {
                var val = row[col];
                var cls = '';
                var display = val == null ? '<span class="explain-null">NULL</span>' : escapeHtml(String(val));

                if (col === 'type' && val) {
                    cls = 'explain-type explain-type-' + (typeClass[val] || '');
                    display = '<span class="' + cls + '">' + escapeHtml(val) + '</span>';
                    cls = '';
                } else if (col === 'Extra' && val) {
                    var extraCls = classifyExtra(val);
                    display = '';
                    val.split('; ').forEach(function(part) {
                        var partCls = classifyExtra(part) || extraCls;
                        display += '<span class="explain-extra-tag ' + (partCls ? 'explain-extra-' + partCls : '') + '">' + escapeHtml(part) + '</span>';
                    });
                } else if (col === 'rows' && val != null) {
                    cls = 'explain-rows ' + rowsCellClass(val);
                    display = formatNum(val);
                } else if (col === 'key' || col === 'possible_keys') {
                    if (val == null) {
                        display = '<span class="explain-null">—</span>';
                    } else {
                        display = '<code>' + escapeHtml(String(val)) + '</code>';
                    }
                } else if (col === 'table' && val) {
                    display = '<code class="explain-table-name">' + escapeHtml(val) + '</code>';
                } else if (col === 'id') {
                    cls = 'explain-id';
                }

                html += cls ? '<td class="' + cls + '">' : '<td>';
                html += display + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';

        // Legend
        html += '<div class="explain-legend">';
        html += '<span class="legend-item"><span class="legend-swatch good"></span> Efficient</span>';
        html += '<span class="legend-item"><span class="legend-swatch ok"></span> OK</span>';
        html += '<span class="legend-item"><span class="legend-swatch warn"></span> Worth reviewing</span>';
        html += '<span class="legend-item"><span class="legend-swatch bad"></span> Full scan / slow</span>';
        html += '</div>';

        body.innerHTML = html;
        meta.textContent = data.time + ' ms · ' + data.rows.length + ' step' + (data.rows.length === 1 ? '' : 's');
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    btn.addEventListener('click', function() {
        var editor = document.getElementById('sql-editor');
        if (!editor) return;
        // Use selected text if any, otherwise full editor
        var selStart = editor.selectionStart, selEnd = editor.selectionEnd;
        var sql = (selStart !== selEnd) ? editor.value.substring(selStart, selEnd) : editor.value;
        sql = sql.trim();
        if (!sql) { Ledger.setStatus('Nothing to explain.'); return; }

        btn.disabled = true;
        var orig = btn.innerHTML;
        btn.textContent = 'Explaining…';

        var fd = new FormData();
        fd.append('action', 'explain_query');
        fd.append('db', <?= json_encode($currentDb ?? '') ?>);
        fd.append('sql', sql);
        fd.append('_csrf_token', Ledger.getCsrfToken());

        fetch('ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = orig;
                panel.style.display = '';
                renderResults(data);
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = orig;
                renderResults({ error: err.message });
            });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            panel.style.display = 'none';
        });
    }
});
</script>
