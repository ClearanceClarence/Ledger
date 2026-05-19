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

            if ($importType === 'sql') {
                // SQL Import — stream directly from the uploaded temp file
                // so memory stays bounded regardless of dump size.
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
                    // Lift PHP's time limit for the duration of the import. Large
                    // dumps can easily exceed the default 30 seconds.
                    @set_time_limit(0);

                    // Same fast-mode toggle as the streaming path
                    $fast = !isset($_POST['fast']) || $_POST['fast'] !== '0';
                    $start = microtime(true);
                    try {
                        $aggregate = $dbInstance->executeSqlDumpFromFile($targetDb, $filePath, null, 250, $fast);
                    } catch (\RuntimeException $e) {
                        $importResults = ['error' => 'Import failed: ' . $e->getMessage()];
                        $aggregate = null;
                    }
                    $elapsed = microtime(true) - $start;

                    if ($aggregate !== null) {
                        $label = $targetDb ? "into {$targetDb}" : "(new database from file)";
                        if (isset($auth)) {
                            $auth->logActivity("SQL import: {$fileName} ({$fileSize} bytes) {$label} — {$aggregate['success']} OK, {$aggregate['errors']} errors");
                        }

                        $importResults = [
                            'type'       => 'sql',
                            'file'       => $fileName,
                            'size'       => $fileSize,
                            'target'     => $targetDb ?? '(from file)',
                            'statements' => $aggregate['statements'],
                            'success'    => $aggregate['success'],
                            'errors'     => $aggregate['errors'],
                            'rows'       => $aggregate['rows'],
                            'total'      => $aggregate['total'],
                            'truncated'  => $aggregate['truncated'],
                            'aborted'    => $aggregate['aborted'] ?? false,
                            'fast'       => $aggregate['fast'] ?? false,
                            'time'       => $elapsed,
                        ];
                    }
                }

            } elseif ($importType === 'csv') {
                // CSV Import — for now we still read the whole file (importCsv()
                // is a candidate for the same streaming treatment as SQL, but
                // CSVs in this product tend to be smaller in practice).
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

                    @set_time_limit(0);
                    $content = file_get_contents($filePath);
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
            <?php if (!empty($importResults['fast']) && !empty($importResults['aborted'])): ?>
            <div class="import-result-aborted">
                <strong>Import aborted.</strong> Fast mode was on, so the failing table's transaction was rolled back.
                Tables committed before the failure (at the previous CREATE/DROP/ALTER boundary) remain in place.
                Re-running the import will re-import everything. To get per-statement isolation instead, turn off Fast mode.
            </div>
            <?php endif; ?>
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
            if (!empty($errorStmts)):
                $totalErrors = $importResults['errors'];
                $shownErrors = count($errorStmts);
                $isTruncated = $totalErrors > $shownErrors;
            ?>
            <details class="import-errors-detail" style="margin-top:12px;">
                <summary style="cursor:pointer;color:var(--danger);font-size:var(--font-size-sm);font-weight:600;">
                    <?= icon('alert-triangle', 12) ?>
                    <?php if ($isTruncated): ?>
                        Showing first <?= $shownErrors ?> of <?= $totalErrors ?> failed statement<?= $totalErrors > 1 ? 's' : '' ?>
                    <?php else: ?>
                        <?= $shownErrors ?> failed statement<?= $shownErrors > 1 ? 's' : '' ?>
                    <?php endif; ?>
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
        <form method="post" enctype="multipart/form-data" class="import-form" id="sql-form" data-stream-import="1">
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

            <!-- Fast mode toggle — wraps INSERTs in transactions for ~10-20x speedup.
                 Default ON. Documented behavior: rolls back the current
                 transaction on error and stops the import. Tables committed
                 before the failure (at the previous DDL boundary) remain. -->
            <label class="import-fast-toggle">
                <input type="checkbox" name="fast" value="1" checked id="sql-fast-toggle">
                <span class="import-fast-label">
                    <strong>Fast mode</strong>
                    <span class="import-fast-desc">
                        Wraps inserts in transactions and disables foreign-key / unique checks during the import. Much faster on large dumps (often 10–20×). On error, the current table rolls back and the import stops.
                    </span>
                </span>
            </label>

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

        <!-- Live progress UI — shown only during a streaming import -->
        <div class="import-progress" id="sql-progress" style="display:none;">
            <div class="import-progress-row">
                <div class="import-progress-phase" id="sql-progress-phase">Preparing…</div>
                <div class="import-progress-elapsed" id="sql-progress-elapsed"></div>
            </div>
            <div class="import-progress-bar-track">
                <div class="import-progress-bar-fill" id="sql-progress-fill" style="width:0"></div>
                <div class="import-progress-bar-indeterminate" id="sql-progress-indeterminate" style="display:none;"></div>
            </div>
            <div class="import-progress-stats" id="sql-progress-stats"></div>
        </div>

        <!-- Inline result card rendered from the streamed result -->
        <div class="import-stream-result" id="sql-stream-result" style="display:none;"></div>
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

    // ─── Streaming SQL Import ─────────────────────────────────────────
    // Intercepts the SQL form submit, uploads via XHR (for upload progress),
    // and parses NDJSON progress events as they stream in. Falls back to
    // the regular form POST if anything goes wrong before the request even
    // starts (no file, no JS support for FormData, etc.)
    var sqlForm = document.getElementById('sql-form');
    if (sqlForm && window.FormData && window.XMLHttpRequest) {
        sqlForm.addEventListener('submit', function(evt) {
            // Only intercept if a file is actually selected — let the form
            // submit normally so HTML5 required-field validation works.
            var fileInput = document.getElementById('sql-file-input');
            if (!fileInput || !fileInput.files.length) return;

            evt.preventDefault();
            runStreamingImport(sqlForm);
        });
    }

    function runStreamingImport(form) {
        var progressEl  = document.getElementById('sql-progress');
        var phaseEl     = document.getElementById('sql-progress-phase');
        var elapsedEl   = document.getElementById('sql-progress-elapsed');
        var fillEl      = document.getElementById('sql-progress-fill');
        var indetEl     = document.getElementById('sql-progress-indeterminate');
        var statsEl     = document.getElementById('sql-progress-stats');
        var resultEl    = document.getElementById('sql-stream-result');
        var submitBtn   = form.querySelector('button[type="submit"]');

        // Reset UI
        progressEl.style.display = '';
        resultEl.style.display = 'none';
        resultEl.innerHTML = '';
        fillEl.style.width = '0';
        indetEl.style.display = 'none';
        phaseEl.textContent = 'Uploading…';
        elapsedEl.textContent = '';
        statsEl.textContent = '';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.dataset.originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = 'Importing…';
        }

        var fd = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax.php?action=import_stream', true);

        // Track bytes already consumed from xhr.responseText. NDJSON lines
        // arrive incrementally and we parse them as soon as we have a
        // complete line.
        var consumed = 0;
        var buffer = '';
        var startTime = Date.now();
        var importStarted = false;

        // ─── Upload phase ────────────────────────────────────────────
        xhr.upload.addEventListener('progress', function(e) {
            if (!e.lengthComputable) return;
            var pct = Math.floor(e.loaded / e.total * 100);
            fillEl.style.width = pct + '%';
            phaseEl.textContent = 'Uploading';
            statsEl.textContent =
                formatBytes(e.loaded) + ' / ' + formatBytes(e.total) +
                ' (' + pct + '%)';
            elapsedEl.textContent = formatElapsed((Date.now() - startTime) / 1000);
        });

        xhr.upload.addEventListener('load', function() {
            // Upload's done; server is now processing
            phaseEl.textContent = 'Counting statements…';
            statsEl.textContent = '';
            fillEl.style.width = '0';
            indetEl.style.display = '';
            importStarted = true;
        });

        // ─── Streaming response phase ────────────────────────────────
        // 'progress' fires as response bytes arrive. We re-slice
        // xhr.responseText from where we last parsed, append to our
        // buffer, and process complete newline-delimited JSON lines.
        xhr.addEventListener('progress', function() {
            var fresh = xhr.responseText.substring(consumed);
            consumed = xhr.responseText.length;
            buffer += fresh;

            var newlineIdx;
            while ((newlineIdx = buffer.indexOf('\n')) >= 0) {
                var line = buffer.substring(0, newlineIdx).trim();
                buffer = buffer.substring(newlineIdx + 1);
                if (line === '') continue;
                try {
                    var msg = JSON.parse(line);
                    handleProgressEvent(msg);
                } catch (e) {
                    // Malformed line — ignore and keep going
                }
            }
        });

        xhr.addEventListener('load', function() {
            // Process anything left in the buffer (rare — server usually
            // ends with a newline) and re-enable the submit button.
            if (buffer.trim() !== '') {
                try { handleProgressEvent(JSON.parse(buffer.trim())); } catch (e) {}
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                if (submitBtn.dataset.originalText) {
                    submitBtn.innerHTML = submitBtn.dataset.originalText;
                }
            }
        });

        xhr.addEventListener('error', function() {
            showStreamError('Network error during import. Please try again.');
            if (submitBtn) {
                submitBtn.disabled = false;
                if (submitBtn.dataset.originalText) {
                    submitBtn.innerHTML = submitBtn.dataset.originalText;
                }
            }
        });

        xhr.send(fd);

        // ─── Event dispatch ─────────────────────────────────────────
        function handleProgressEvent(msg) {
            if (msg.phase === 'counting') {
                phaseEl.textContent = 'Counting statements…';
                indetEl.style.display = '';
                fillEl.style.width = '0';
                statsEl.textContent = msg.file
                    ? msg.file + ' · ' + formatBytes(msg.size)
                    : '';
            }
            else if (msg.phase === 'counted') {
                indetEl.style.display = 'none';
                phaseEl.textContent = 'Importing';
                statsEl.textContent =
                    '0 / ' + msg.estimate.toLocaleString() + ' statements';
            }
            else if (msg.phase === 'progress') {
                indetEl.style.display = 'none';
                var pct = msg.estimate > 0
                    ? Math.min(100, Math.floor(msg.total / msg.estimate * 100))
                    : 0;
                fillEl.style.width = pct + '%';
                phaseEl.textContent = 'Importing';

                var parts = [
                    msg.total.toLocaleString() +
                        (msg.estimate ? ' / ' + msg.estimate.toLocaleString() : '') +
                        ' statements'
                ];
                if (msg.errors > 0) {
                    parts.push('<span class="stat-error">' +
                        msg.errors.toLocaleString() + ' error' +
                        (msg.errors === 1 ? '' : 's') + '</span>');
                }
                statsEl.innerHTML = parts.join('<span class="stat-divider">·</span>');
                elapsedEl.textContent = formatElapsed(msg.elapsed);
            }
            else if (msg.phase === 'done') {
                indetEl.style.display = 'none';
                fillEl.style.width = '100%';
                phaseEl.textContent = 'Done';
                statsEl.innerHTML =
                    msg.total.toLocaleString() + ' statements' +
                    '<span class="stat-divider">·</span>' +
                    msg.success.toLocaleString() + ' ok' +
                    (msg.errors > 0
                        ? '<span class="stat-divider">·</span><span class="stat-error">' +
                          msg.errors.toLocaleString() + ' errors</span>'
                        : '');
                elapsedEl.textContent = formatElapsed(msg.time);
                renderStreamResult(msg);
            }
            else if (msg.phase === 'error') {
                showStreamError(msg.error || 'Unknown error');
            }
        }

        // ─── Final result card (inline render of $importResults) ────
        function renderStreamResult(msg) {
            var hasErrors = msg.errors > 0;
            var errorStmts = (msg.statements || []).filter(function(s) {
                return !s.success;
            });

            var html = '';
            html += '<div class="import-result-card ' +
                (hasErrors ? 'import-result-warn' : 'import-result-ok') + '">';
            html += '<div class="import-result-header">';
            html += '<div class="import-result-title">SQL Import ' +
                (hasErrors ? 'Completed with Errors' : 'Successful') +
                ' — <strong>' + escapeHtml(msg.target) + '</strong></div>';
            html += '</div>';
            html += '<div class="import-result-body">';

            // Fast mode + aborted warning (rolled back current transaction)
            if (msg.fast && msg.aborted) {
                html += '<div class="import-result-aborted">';
                html += '<strong>Import aborted.</strong> Fast mode was on, so the failing table\'s transaction was rolled back. ' +
                    'Tables committed before the failure (at the previous CREATE/DROP/ALTER boundary) remain in place. ' +
                    'Re-running the import will re-import everything. To get per-statement isolation instead, turn off Fast mode.';
                html += '</div>';
            }

            html += '<div class="import-result-stats">';
            html += '<div class="import-stat">' +
                '<span class="import-stat-val accent">' + msg.success.toLocaleString() +
                '</span><span class="import-stat-lbl">Statements OK</span></div>';
            if (hasErrors) {
                html += '<div class="import-stat">' +
                    '<span class="import-stat-val" style="color:var(--danger);">' +
                    msg.errors.toLocaleString() +
                    '</span><span class="import-stat-lbl">Errors</span></div>';
            }
            html += '<div class="import-stat">' +
                '<span class="import-stat-val info">' + msg.rows.toLocaleString() +
                '</span><span class="import-stat-lbl">Rows Affected</span></div>';
            html += '<div class="import-stat">' +
                '<span class="import-stat-val muted">' + msg.time.toFixed(3) + 's' +
                '</span><span class="import-stat-lbl">Duration</span></div>';
            html += '<div class="import-stat">' +
                '<span class="import-stat-val muted">' + formatBytes(msg.size) +
                '</span><span class="import-stat-lbl">' + escapeHtml(msg.file) +
                '</span></div>';
            html += '</div>';

            if (errorStmts.length > 0) {
                var isTrunc = msg.errors > errorStmts.length;
                html += '<details class="import-errors-detail" style="margin-top:12px;">';
                html += '<summary style="cursor:pointer;color:var(--danger);font-size:var(--font-size-sm);font-weight:600;">';
                html += isTrunc
                    ? 'Showing first ' + errorStmts.length + ' of ' +
                      msg.errors.toLocaleString() + ' failed statements'
                    : errorStmts.length + ' failed statement' +
                      (errorStmts.length === 1 ? '' : 's');
                html += '</summary>';
                html += '<div style="margin-top:8px;font-family:var(--font-mono);font-size:var(--font-size-xs);">';
                errorStmts.forEach(function(s) {
                    html += '<div style="margin:6px 0;padding:8px;background:var(--bg-input);border-left:2px solid var(--danger);border-radius:var(--radius-sm);">';
                    html += '<div style="color:var(--text-secondary);">' + escapeHtml(s.sql) + '</div>';
                    html += '<div style="color:var(--danger);margin-top:4px;">' + escapeHtml(s.error || '') + '</div>';
                    html += '</div>';
                });
                html += '</div></details>';
            }

            html += '</div></div>';
            resultEl.innerHTML = html;
            resultEl.style.display = '';
            // Hide the progress bar once results are shown — the result card
            // now carries all the same info
            setTimeout(function() { progressEl.style.display = 'none'; }, 800);
        }

        function showStreamError(err) {
            indetEl.style.display = 'none';
            fillEl.style.width = '0';
            phaseEl.textContent = 'Error';
            statsEl.innerHTML = '';
            resultEl.innerHTML =
                '<div class="error-box">' + escapeHtml(err) + '</div>';
            resultEl.style.display = '';
            progressEl.style.display = 'none';
        }
    }

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
        if (n < 1024*1024*1024) return (n/(1024*1024)).toFixed(1) + ' MB';
        return (n/(1024*1024*1024)).toFixed(2) + ' GB';
    }
    function formatElapsed(seconds) {
        var s = Math.floor(seconds);
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60);
        var rs = s % 60;
        return m + 'm ' + (rs < 10 ? '0' : '') + rs + 's';
    }
    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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
