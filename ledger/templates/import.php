<?php
$isReadOnly = isset($auth) && $auth->isReadOnly();
$csrfToken = isset($auth) ? $auth->generateCsrfToken() : '';
$importResults = null;
$importType = null;

// Handle import POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReadOnly) {
    if (isset($auth) && $auth->csrfEnabled() && !$auth->validateCsrf()) {
        $importResults = ['error' => 'Invalid security token. Please reload and try again.'];
    } else {
        $importType = $_POST['import_type'] ?? '';

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            $errCode = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $importResults = ['error' => $uploadErrors[$errCode] ?? 'Upload failed (code ' . $errCode . ').'];
        } else {
            $filePath = $_FILES['import_file']['tmp_name'];
            $fileName = $_FILES['import_file']['name'];
            $fileSize = $_FILES['import_file']['size'];
            $content = file_get_contents($filePath);

            if ($importType === 'sql') {
                // SQL Import
                $sqlTarget = $_POST['sql_target'] ?? 'existing';
                $targetDb = null;

                if ($sqlTarget === 'existing') {
                    $targetDb = $_POST['target_db'] ?? $currentDb;
                    if (!$targetDb) {
                        $importResults = ['error' => 'No target database selected.'];
                    }
                }
                // sqlTarget === 'new' → targetDb stays null, file must contain CREATE DATABASE / USE

                if (!isset($importResults['error'])) {
                    $start = microtime(true);
                    $results = $dbInstance->executeSqlDump($targetDb, $content);
                    $elapsed = microtime(true) - $start;

                    $successCount = count(array_filter($results, fn($r) => $r['success']));
                    $errorCount = count(array_filter($results, fn($r) => !$r['success']));
                    $totalRows = array_sum(array_map(fn($r) => $r['rows'] ?? 0, $results));

                    $label = $targetDb ? "into {$targetDb}" : "(new database from file)";
                    if (isset($auth)) {
                        $auth->logActivity("SQL import: {$fileName} ({$fileSize} bytes) {$label} — {$successCount} OK, {$errorCount} errors");
                    }

                    $importResults = [
                        'type'       => 'sql',
                        'file'       => $fileName,
                        'size'       => $fileSize,
                        'target'     => $targetDb ?? '(from file)',
                        'statements' => $results,
                        'success'    => $successCount,
                        'errors'     => $errorCount,
                        'rows'       => $totalRows,
                        'time'       => $elapsed,
                    ];
                }

            } elseif ($importType === 'csv') {
                // CSV Import
                $targetDb = $_POST['target_db'] ?? $currentDb;
                $targetTable = $_POST['target_table'] ?? '';
                if (!$targetDb || !$targetTable) {
                    $importResults = ['error' => 'Database and target table are required for CSV import.'];
                } else {
                    $options = [
                        'delimiter'   => $_POST['csv_delimiter'] ?? ',',
                        'enclosure'   => $_POST['csv_enclosure'] ?? '"',
                        'has_header'  => isset($_POST['csv_has_header']),
                        'skip_errors' => true,
                    ];

                    $start = microtime(true);
                    $result = $dbInstance->importCsv($targetDb, $targetTable, $content, $options);
                    $elapsed = microtime(true) - $start;

                    if (isset($auth)) {
                        $auth->logActivity("CSV import: {$fileName} into {$targetDb}.{$targetTable} — {$result['inserted']} inserted, {$result['skipped']} skipped");
                    }

                    $importResults = [
                        'type'     => 'csv',
                        'file'     => $fileName,
                        'size'     => $fileSize,
                        'table'    => $targetTable,
                        'inserted' => $result['inserted'],
                        'skipped'  => $result['skipped'],
                        'errors'   => $result['errors'],
                        'time'     => $elapsed,
                    ];
                }
            }
        }
    }
}

// Get tables for CSV target dropdown
$dbTables = [];
if ($currentDb) {
    try { $dbTables = $dbInstance->getTables($currentDb); } catch (Exception $e) {}
}
?>

<!-- Header -->
<div class="info-header info-header-red">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('upload', 24) ?></div>
        <div>
            <h3 class="info-header-title">Import</h3>
            <span class="info-header-sub"><?= $currentDb ? h($currentDb) : 'Import SQL or CSV files' ?></span>
        </div>
    </div>
</div>

<?php if ($isReadOnly): ?>
<div class="error-box" style="margin-top:16px;">
    <?= icon('eye', 14) ?> Import is disabled in read-only mode.
</div>
<?php return; endif; ?>

<!-- Import Results -->
<?php if ($importResults): ?>
<div class="import-results" style="margin-top:16px;">

    <?php if (isset($importResults['error']) && is_string($importResults['error'] ?? null)): ?>
    <div class="error-box"><?= icon('alert-triangle', 14) ?> <?= h($importResults['error']) ?></div>

    <?php elseif (($importResults['type'] ?? '') === 'sql'): ?>
    <div class="import-result-card <?= $importResults['errors'] > 0 ? 'import-result-warn' : 'import-result-ok' ?>">
        <div class="import-result-header">
            <?= icon($importResults['errors'] > 0 ? 'alert-triangle' : 'check', 18) ?>
            <div class="import-result-title">
                SQL Import <?= $importResults['errors'] > 0 ? 'Completed with Errors' : 'Successful' ?>
                — <strong><?= h($importResults['target']) ?></strong>
            </div>
        </div>
        <div class="import-result-body">
            <div class="import-result-stats">
                <div class="import-stat">
                    <span class="import-stat-val accent"><?= $importResults['success'] ?></span>
                    <span class="import-stat-lbl">Statements OK</span>
                </div>
                <?php if ($importResults['errors'] > 0): ?>
                <div class="import-stat">
                    <span class="import-stat-val" style="color:var(--danger);"><?= $importResults['errors'] ?></span>
                    <span class="import-stat-lbl">Errors</span>
                </div>
                <?php endif; ?>
                <div class="import-stat">
                    <span class="import-stat-val info"><?= format_number($importResults['rows']) ?></span>
                    <span class="import-stat-lbl">Rows Affected</span>
                </div>
                <div class="import-stat">
                    <span class="import-stat-val muted"><?= number_format($importResults['time'], 3) ?>s</span>
                    <span class="import-stat-lbl">Duration</span>
                </div>
                <div class="import-stat">
                    <span class="import-stat-val muted"><?= format_bytes($importResults['size']) ?></span>
                    <span class="import-stat-lbl"><?= h($importResults['file']) ?></span>
                </div>
            </div>

            <?php
            $errorStmts = array_filter($importResults['statements'], fn($r) => !$r['success']);
            if (!empty($errorStmts)): ?>
            <details class="import-errors-detail" style="margin-top:12px;">
                <summary style="cursor:pointer;color:var(--danger);font-size:var(--font-size-sm);font-weight:600;">
                    <?= icon('alert-triangle', 12) ?> <?= count($errorStmts) ?> failed statement<?= count($errorStmts) > 1 ? 's' : '' ?>
                </summary>
                <div class="table-wrapper" style="margin-top:8px;">
                    <table class="data-table">
                        <thead><tr><th>Statement</th><th>Error</th></tr></thead>
                        <tbody>
                            <?php foreach ($errorStmts as $es): ?>
                            <tr>
                                <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);max-width:300px;overflow:hidden;text-overflow:ellipsis;"><?= h($es['sql']) ?></td>
                                <td style="color:var(--danger);font-size:var(--font-size-xs);"><?= h($es['error']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif (($importResults['type'] ?? '') === 'csv'): ?>
    <div class="import-result-card <?= !empty($importResults['errors']) ? 'import-result-warn' : 'import-result-ok' ?>">
        <div class="import-result-header">
            <?= icon(!empty($importResults['errors']) ? 'alert-triangle' : 'check', 18) ?>
            <div class="import-result-title">
                CSV Import into <strong><?= h($importResults['table']) ?></strong>
            </div>
        </div>
        <div class="import-result-body">
            <div class="import-result-stats">
                <div class="import-stat">
                    <span class="import-stat-val accent"><?= format_number($importResults['inserted']) ?></span>
                    <span class="import-stat-lbl">Rows Inserted</span>
                </div>
                <?php if ($importResults['skipped'] > 0): ?>
                <div class="import-stat">
                    <span class="import-stat-val" style="color:var(--warning);"><?= $importResults['skipped'] ?></span>
                    <span class="import-stat-lbl">Skipped</span>
                </div>
                <?php endif; ?>
                <div class="import-stat">
                    <span class="import-stat-val muted"><?= number_format($importResults['time'], 3) ?>s</span>
                    <span class="import-stat-lbl">Duration</span>
                </div>
                <div class="import-stat">
                    <span class="import-stat-val muted"><?= format_bytes($importResults['size']) ?></span>
                    <span class="import-stat-lbl"><?= h($importResults['file']) ?></span>
                </div>
            </div>

            <?php if (!empty($importResults['errors'])): ?>
            <details class="import-errors-detail" style="margin-top:12px;">
                <summary style="cursor:pointer;color:var(--danger);font-size:var(--font-size-sm);font-weight:600;">
                    <?= icon('alert-triangle', 12) ?> <?= count($importResults['errors']) ?> error<?= count($importResults['errors']) > 1 ? 's' : '' ?>
                </summary>
                <div style="margin-top:8px;font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--danger);">
                    <?php foreach ($importResults['errors'] as $err): ?>
                    <div style="padding:3px 0;"><?= h($err) ?></div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Import Forms -->
<div class="import-forms">

    <!-- SQL Import (always available) -->
    <div class="import-section">
        <div class="import-section-header">
            <span class="import-section-icon" style="color:var(--accent);"><?= icon('code', 22) ?></span>
            <div>
                <div class="import-section-title">Import SQL</div>
                <div class="import-section-desc">Upload a <code>.sql</code> file. Supports full database dumps with <code>CREATE DATABASE</code> or table-level imports into an existing database.</div>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="import-form">
            <?php if (isset($auth)): ?><?= $auth->csrfField() ?><?php endif; ?>
            <input type="hidden" name="import_type" value="sql">

            <!-- Target Mode -->
            <div class="import-target-mode">
                <label class="import-mode-option">
                    <input type="radio" name="sql_target" value="existing" <?= $currentDb ? 'checked' : '' ?> onchange="document.getElementById('sql-db-select').style.display=''">
                    <div class="import-mode-card">
                        <?= icon('database', 16) ?>
                        <div>
                            <div class="import-mode-title">Into existing database</div>
                            <div class="import-mode-desc">Execute statements inside a selected database</div>
                        </div>
                    </div>
                </label>
                <label class="import-mode-option">
                    <input type="radio" name="sql_target" value="new" <?= !$currentDb ? 'checked' : '' ?> onchange="document.getElementById('sql-db-select').style.display='none'">
                    <div class="import-mode-card">
                        <?= icon('plus', 16) ?>
                        <div>
                            <div class="import-mode-title">New database from file</div>
                            <div class="import-mode-desc">File contains CREATE DATABASE and USE statements</div>
                        </div>
                    </div>
                </label>
            </div>

            <!-- Database selector (shown for "existing" mode) -->
            <div id="sql-db-select" class="settings-field" style="margin-top:12px;<?= !$currentDb ? 'display:none;' : '' ?>">
                <label class="settings-label">Target Database</label>
                <select name="target_db" class="settings-input">
                    <option value="">— Select database —</option>
                    <?php foreach ($databases as $dbName): ?>
                    <option value="<?= h($dbName) ?>" <?= $dbName === $currentDb ? 'selected' : '' ?>><?= h($dbName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- File upload -->
            <div class="import-upload-area" id="sql-drop-zone" style="margin-top:14px;">
                <?= icon('upload', 20) ?>
                <div class="import-upload-text">
                    <strong>Choose a .sql file</strong> or drag and drop
                </div>
                <div class="import-upload-meta">Max size: <?= h(ini_get('upload_max_filesize')) ?></div>
                <input type="file" name="import_file" accept=".sql,.txt" required class="import-file-input" id="sql-file-input">
            </div>
            <div class="import-file-name" id="sql-file-name"></div>

            <!-- Preview panel -->
            <div class="sql-preview" id="sql-preview" style="display:none;">
                <div class="sql-preview-header">
                    <span class="sql-preview-title"><?= icon('terminal', 13) ?> Preview</span>
                    <div class="sql-preview-meta">
                        <span id="sql-preview-lines">0 lines</span>
                        <span id="sql-preview-stmts">· 0 statements</span>
                        <button type="button" class="sql-preview-toggle" id="sql-preview-toggle" title="Toggle preview">
                            <?= icon('chevron-down', 13) ?>
                        </button>
                    </div>
                </div>
                <pre class="sql-preview-body" id="sql-preview-body"></pre>
            </div>

            <div class="import-form-footer">
                <div class="import-target">
                    <?= icon('upload', 13) ?>
                    <span>SQL statements will be executed sequentially</span>
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= icon('play', 14) ?> Execute SQL File
                </button>
            </div>
        </form>
    </div>

    <!-- CSV Import (requires db) -->
    <div class="import-section">
        <div class="import-section-header">
            <span class="import-section-icon" style="color:var(--info);"><?= icon('file-text', 22) ?></span>
            <div>
                <div class="import-section-title">Import CSV</div>
                <div class="import-section-desc">Upload a <code>.csv</code> file and insert rows into an existing table. Requires a database and table to be selected.</div>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="import-form">
            <?php if (isset($auth)): ?><?= $auth->csrfField() ?><?php endif; ?>
            <input type="hidden" name="import_type" value="csv">

            <div class="import-options">
                <div class="settings-grid">
                    <div class="settings-field">
                        <label class="settings-label">Database</label>
                        <select name="target_db" class="settings-input" id="csv-db-select" onchange="Ledger.loadCsvTables(this.value)">
                            <option value="">— Select database —</option>
                            <?php foreach ($databases as $dbName): ?>
                            <option value="<?= h($dbName) ?>" <?= $dbName === $currentDb ? 'selected' : '' ?>><?= h($dbName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label class="settings-label">Target Table</label>
                        <select name="target_table" class="settings-input" id="csv-table-select" required>
                            <option value="">— Select table —</option>
                            <?php foreach ($dbTables as $t): ?>
                            <option value="<?= h($t['Name']) ?>" <?= ($currentTable === $t['Name']) ? 'selected' : '' ?>>
                                <?= h($t['Name']) ?> (<?= format_number($t['Rows'] ?? 0) ?> rows)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="settings-grid" style="margin-top:8px;">
                    <div class="settings-field" style="flex:0.5;">
                        <label class="settings-label">Delimiter</label>
                        <select name="csv_delimiter" class="settings-input">
                            <option value="," selected>Comma (,)</option>
                            <option value=";">Semicolon (;)</option>
                            <option value="&#9;">Tab</option>
                            <option value="|">Pipe (|)</option>
                        </select>
                    </div>
                    <div class="settings-field" style="flex:0.4;">
                        <label class="settings-label">Enclosure</label>
                        <select name="csv_enclosure" class="settings-input">
                            <option value="&quot;" selected>Double quote (")</option>
                            <option value="'">Single quote (')</option>
                        </select>
                    </div>
                    <div class="settings-field" style="flex:0.5;">
                        <label class="settings-label">&nbsp;</label>
                        <label class="settings-check" style="padding:8px 0;">
                            <input type="checkbox" name="csv_has_header" checked>
                            First row is headers
                        </label>
                    </div>
                </div>
            </div>

            <!-- File upload -->
            <div class="import-upload-area" id="csv-drop-zone" style="margin-top:14px;">
                <?= icon('upload', 20) ?>
                <div class="import-upload-text">
                    <strong>Choose a .csv file</strong> or drag and drop
                </div>
                <div class="import-upload-meta">Max size: <?= h(ini_get('upload_max_filesize')) ?></div>
                <input type="file" name="import_file" accept=".csv,.tsv,.txt" required class="import-file-input" id="csv-file-input">
            </div>
            <div class="import-file-name" id="csv-file-name"></div>

            <div class="import-form-footer">
                <div class="import-target">
                    <?= icon('upload', 13) ?>
                    <span>Rows will be inserted via prepared statements</span>
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= icon('upload', 14) ?> Import CSV
                </button>
            </div>
        </form>
    </div>

</div>

<!-- Drag & drop + CSV table loader -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Drag & drop
    ['sql', 'csv'].forEach(function(type) {
        var zone = document.getElementById(type + '-drop-zone');
        var input = document.getElementById(type + '-file-input');
        var nameEl = document.getElementById(type + '-file-name');
        if (!zone || !input) return;

        zone.addEventListener('click', function(e) {
            if (e.target !== input) input.click();
        });

        input.addEventListener('change', function() {
            if (input.files.length) {
                var f = input.files[0];
                nameEl.textContent = f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
                nameEl.style.display = 'block';
                zone.classList.add('import-upload-has-file');

                // Preview for SQL files
                if (type === 'sql') {
                    renderSqlPreview(f);
                }
            }
        });

        zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('import-upload-drag'); });
        zone.addEventListener('dragleave', function() { zone.classList.remove('import-upload-drag'); });
        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            zone.classList.remove('import-upload-drag');
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });

    // SQL Preview
    function renderSqlPreview(file) {
        var panel = document.getElementById('sql-preview');
        var body = document.getElementById('sql-preview-body');
        var linesEl = document.getElementById('sql-preview-lines');
        var stmtsEl = document.getElementById('sql-preview-stmts');
        if (!panel || !body) return;

        var MAX_BYTES = 256 * 1024; // 256 KB read cap
        var truncated = file.size > MAX_BYTES;
        var slice = truncated ? file.slice(0, MAX_BYTES) : file;

        var reader = new FileReader();
        reader.onload = function(e) {
            var text = e.target.result;
            if (truncated) text += '\n\n-- … file truncated for preview (showing first 256 KB of ' +
                (file.size / 1024).toFixed(1) + ' KB) …';

            var lines = text.split('\n').length;
            // Rough statement count — split on `;` outside strings
            var stmtCount = 0;
            var inStr = false, strCh = '';
            for (var i = 0; i < text.length; i++) {
                var c = text[i];
                if (inStr) {
                    if (c === '\\') { i++; continue; }
                    if (c === strCh) inStr = false;
                } else {
                    if (c === "'" || c === '"' || c === '`') { inStr = true; strCh = c; }
                    else if (c === ';') stmtCount++;
                }
            }

            linesEl.textContent = lines.toLocaleString() + ' lines';
            stmtsEl.textContent = '· ' + stmtCount.toLocaleString() + ' statement' + (stmtCount === 1 ? '' : 's');

            // Syntax highlight via Ledger tokenizer
            if (typeof Ledger !== 'undefined' && Ledger.tokenize) {
                var tokens = Ledger.tokenize(text);
                body.innerHTML = Ledger.renderTokens(tokens);
            } else {
                body.textContent = text;
            }

            panel.style.display = '';
        };
        reader.readAsText(slice);
    }

    // Toggle collapse
    var toggleBtn = document.getElementById('sql-preview-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var body = document.getElementById('sql-preview-body');
            var collapsed = body.style.display === 'none';
            body.style.display = collapsed ? '' : 'none';
            toggleBtn.style.transform = collapsed ? '' : 'rotate(-90deg)';
        });
    }
});

// Load tables when CSV database changes
if (typeof Ledger !== 'undefined') {
    Ledger.loadCsvTables = function(db) {
        var sel = document.getElementById('csv-table-select');
        if (!sel) return;
        sel.innerHTML = '<option value="">Loading…</option>';
        if (!db) { sel.innerHTML = '<option value="">— Select database first —</option>'; return; }

        fetch('ajax.php?action=autocomplete&db=' + encodeURIComponent(db))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var html = '<option value="">— Select table —</option>';
                (data.tables || []).forEach(function(t) {
                    html += '<option value="' + t.name + '">' + t.name + ' (' + t.rows + ' rows)</option>';
                });
                sel.innerHTML = html;
            })
            .catch(function() {
                sel.innerHTML = '<option value="">— Error loading tables —</option>';
            });
    };
}
</script>
