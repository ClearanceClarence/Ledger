<?php if (!$currentDb):
    // Server Overview — all databases
    $dbStats = [];
    $totalTables = 0;
    $totalSize = 0;
    foreach ($databases as $dbName) {
        $stat = ['name' => $dbName, 'tables' => 0, 'rows' => 0, 'data_size' => 0, 'index_size' => 0, 'collation' => '—'];
        try {
            $tables = $dbInstance->getTables($dbName);
            $stat['tables'] = count($tables);
            $totalTables += $stat['tables'];
            foreach ($tables as $t) {
                $stat['rows'] += (int)($t['Rows'] ?? 0);
                $stat['data_size'] += (int)($t['Data_length'] ?? 0);
                $stat['index_size'] += (int)($t['Index_length'] ?? 0);
            }
            if (!empty($tables[0]['Collation'])) {
                $stat['collation'] = $tables[0]['Collation'];
            }
            $totalSize += $stat['data_size'] + $stat['index_size'];
        } catch (Exception $e) {
            // skip inaccessible dbs
        }
        $dbStats[] = $stat;
    }
?>

<!-- Server Stats -->
<h3 class="section-title"><?= icon('server', 16) ?> Server Overview</h3>

<div class="info-grid" style="margin-bottom:20px;">
    <div class="info-card">
        <div class="info-card-icon accent-bg"><?= icon('database', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Databases</div>
            <div class="info-value accent"><?= count($databases) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon info-bg"><?= icon('table', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Total Tables</div>
            <div class="info-value info"><?= format_number($totalTables) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon warning-bg"><?= icon('zap', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Total Size</div>
            <div class="info-value warning"><?= format_bytes($totalSize) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon purple-bg"><?= icon('server', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">MySQL Version</div>
            <div class="info-value purple"><?= h($serverVersion) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon gold-bg"><?= icon('terminal', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">PHP Version</div>
            <div class="info-value gold"><?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon muted-bg"><?= icon('activity', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Server</div>
            <div class="info-value muted"><?= h($serverHost) ?></div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div style="display:flex;gap:8px;margin-bottom:20px;">
    <a href="?tab=server" class="btn btn-ghost btn-sm"><?= icon('settings', 13) ?> Server Details</a>
    <a href="?tab=sql" class="btn btn-ghost btn-sm"><?= icon('terminal', 13) ?> SQL Query</a>
    <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
    <button type="button" class="btn btn-primary btn-sm" id="home-create-db-btn"><?= icon('plus', 13) ?> Create Database</button>
    <?php endif; ?>
</div>

<!-- Create Database Form (hidden) -->
<?php if (!(isset($auth) && $auth->isReadOnly())): ?>
<div id="create-db-form" class="create-db-form" style="display:none;">
    <div class="settings-section">
        <h3 class="section-title"><?= icon('plus', 16) ?> Create New Database</h3>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label">Database Name</label>
                <input type="text" id="create-db-name" class="settings-input" placeholder="my_database"
                       pattern="[a-zA-Z0-9_\-]+" title="Letters, numbers, underscores, hyphens only">
            </div>
            <div class="settings-field">
                <label class="settings-label">Character Set</label>
                <select id="create-db-charset" class="settings-input">
                    <option value="utf8mb4" selected>utf8mb4 (recommended)</option>
                    <option value="utf8">utf8</option>
                    <option value="latin1">latin1</option>
                    <option value="ascii">ascii</option>
                    <option value="binary">binary</option>
                </select>
            </div>
            <div class="settings-field">
                <label class="settings-label">Collation</label>
                <select id="create-db-collation" class="settings-input">
                    <option value="utf8mb4_general_ci" selected>utf8mb4_general_ci</option>
                    <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                    <option value="utf8mb4_bin">utf8mb4_bin</option>
                    <option value="utf8_general_ci">utf8_general_ci</option>
                    <option value="latin1_swedish_ci">latin1_swedish_ci</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <button type="button" class="btn btn-primary btn-sm" id="create-db-submit">
                <?= icon('plus', 13) ?> Create Database
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="create-db-cancel">
                <?= icon('x', 13) ?> Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Database List -->
<div class="table-toolbar">
    <h3 class="section-title" style="margin-bottom:0;"><?= icon('database', 15) ?> All Databases</h3>
    <span class="toolbar-info"><?= count($databases) ?> databases</span>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Database</th>
                <th>Tables</th>
                <th>Rows (est.)</th>
                <th>Data Size</th>
                <th>Index Size</th>
                <th>Total Size</th>
                <th>Collation</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dbStats as $stat): ?>
            <tr>
                <td>
                    <a href="?db=<?= urlencode($stat['name']) ?>" style="color:var(--warning);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;">
                        <?= icon('database', 14) ?> <?= h($stat['name']) ?>
                    </a>
                </td>
                <td class="cell-number"><?= format_number($stat['tables']) ?></td>
                <td class="cell-number"><?= format_number($stat['rows']) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($stat['data_size']) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($stat['index_size']) ?></td>
                <td style="color:var(--text-primary);font-weight:500;"><?= format_bytes($stat['data_size'] + $stat['index_size']) ?></td>
                <td style="color:var(--text-muted);font-size:var(--font-size-xs);"><?= h($stat['collation']) ?></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="?db=<?= urlencode($stat['name']) ?>&tab=browse" class="btn btn-ghost btn-sm" title="Browse"><?= icon('table', 13) ?></a>
                        <a href="?db=<?= urlencode($stat['name']) ?>&tab=sql" class="btn btn-ghost btn-sm" title="SQL"><?= icon('terminal', 13) ?></a>
                        <a href="?db=<?= urlencode($stat['name']) ?>&action=export_db" class="btn btn-ghost btn-sm" title="Export"><?= icon('download', 13) ?></a>
                        <?php if (!(isset($auth) && $auth->isReadOnly()) && !in_array(strtolower($stat['name']), ['mysql','information_schema','performance_schema','sys'])): ?>
                        <button type="button" class="btn btn-danger btn-sm drop-db-btn" data-db="<?= h($stat['name']) ?>" title="Drop database" style="padding:2px 6px;"><?= icon('trash', 12) ?></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;">
                <td>Total: <?= count($databases) ?> databases</td>
                <td class="cell-number"><?= format_number($totalTables) ?></td>
                <td></td>
                <td></td>
                <td></td>
                <td style="color:var(--accent);"><?= format_bytes($totalSize) ?></td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php return; endif; ?>

<?php if ($currentDb && !$currentTable):
    // Database Overview
    $isReadOnly = isset($auth) && $auth->isReadOnly();
    $favUser = (isset($auth) && $auth->isLoggedIn()) ? $auth->getUsername() : 'anonymous';
    $favSet = [];
    foreach (ledger_favorites_get($favUser) as $f) {
        $favSet[$f['db'] . '.' . $f['table']] = true;
    }
    try {
        $allTables = $dbInstance->getTables($currentDb);
    } catch (Exception $e) {
        echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
        return;
    }

    $totalRows = 0;
    $totalDataSize = 0;
    $totalIndexSize = 0;
    $exactCounts = [];
    foreach ($allTables as $t) {
        $tName = $t['Name'];
        try {
            $exactCounts[$tName] = $dbInstance->getExactRowCount($currentDb, $tName);
        } catch (Exception $e) {
            $exactCounts[$tName] = (int)($t['Rows'] ?? 0);
        }
        $totalRows += $exactCounts[$tName];
        $totalDataSize += (int)($t['Data_length'] ?? 0);
        $totalIndexSize += (int)($t['Index_length'] ?? 0);
    }
    $totalSize = $totalDataSize + $totalIndexSize;
?>

<!-- Database Stats -->
<h3 class="section-title">Database: <span class="highlight"><?= h($currentDb) ?></span></h3>

<div class="info-grid" style="margin-bottom:20px;">
    <div class="info-card">
        <div class="info-card-icon accent-bg"><?= icon('table', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Tables</div>
            <div class="info-value accent"><?= count($allTables) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon info-bg"><?= icon('database', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Total Rows</div>
            <div class="info-value info"><?= format_number($totalRows) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon warning-bg"><?= icon('download', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Data Size</div>
            <div class="info-value warning"><?= format_bytes($totalDataSize) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon purple-bg"><?= icon('key', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Index Size</div>
            <div class="info-value purple"><?= format_bytes($totalIndexSize) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon gold-bg"><?= icon('zap', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Total Size</div>
            <div class="info-value gold"><?= format_bytes($totalSize) ?></div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-card-icon muted-bg"><?= icon('settings', 16) ?></div>
        <div class="info-card-text">
            <div class="info-label">Default Collation</div>
            <div class="info-value muted" style="font-size:var(--font-size-sm);"><?= h($allTables[0]['Collation'] ?? '—') ?></div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div style="display:flex;gap:8px;margin-bottom:20px;">
    <a href="?db=<?= urlencode($currentDb) ?>&tab=sql" class="btn btn-primary btn-sm"><?= icon('terminal', 13) ?> SQL Query</a>
    <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
    <button type="button" class="btn btn-primary btn-sm" id="create-table-btn"><?= icon('plus', 13) ?> Create Table</button>
    <?php endif; ?>
    <a href="?db=<?= urlencode($currentDb) ?>&action=export_db" class="btn btn-ghost btn-sm"><?= icon('download', 13) ?> Export Database</a>
    <a href="?tab=server" class="btn btn-ghost btn-sm"><?= icon('settings', 13) ?> Server Info</a>
</div>

<!-- Create Table Form -->
<?php if (!(isset($auth) && $auth->isReadOnly())): ?>
<div id="create-table-form" style="display:none;margin-bottom:20px;">
    <div class="settings-section">
        <h3 class="section-title"><?= icon('plus', 16) ?> Create New Table</h3>

        <!-- Table Options -->
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label">Table Name</label>
                <input type="text" id="ct-name" class="settings-input" placeholder="my_table">
            </div>
            <div class="settings-field" style="flex:0.6;">
                <label class="settings-label">Engine</label>
                <select id="ct-engine" class="settings-input">
                    <option value="InnoDB" selected>InnoDB</option>
                    <option value="MyISAM">MyISAM</option>
                    <option value="MEMORY">MEMORY</option>
                    <option value="ARCHIVE">ARCHIVE</option>
                </select>
            </div>
            <div class="settings-field" style="flex:0.8;">
                <label class="settings-label">Collation</label>
                <select id="ct-collation" class="settings-input">
                    <option value="utf8mb4_general_ci" selected>utf8mb4_general_ci</option>
                    <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                    <option value="utf8mb4_bin">utf8mb4_bin</option>
                    <option value="utf8_general_ci">utf8_general_ci</option>
                    <option value="latin1_swedish_ci">latin1_swedish_ci</option>
                </select>
            </div>
            <div class="settings-field">
                <label class="settings-label">Comment</label>
                <input type="text" id="ct-comment" class="settings-input" placeholder="Optional">
            </div>
        </div>

        <!-- Column Definitions -->
        <div class="ct-columns-header">
            <span class="settings-label" style="margin:0;">Columns</span>
            <button type="button" class="btn btn-ghost btn-sm" id="ct-add-col"><?= icon('plus', 12) ?> Add Column</button>
        </div>

        <div class="table-wrapper" style="margin-top:8px;">
            <table class="data-table" id="ct-columns-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th style="width:50px;">Null</th>
                        <th>Default</th>
                        <th style="width:40px;" title="Primary Key">PK</th>
                        <th style="width:40px;" title="Auto Increment">AI</th>
                        <th style="width:40px;" title="Unique">UQ</th>
                        <th style="width:40px;" title="Index">IDX</th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody id="ct-columns-body">
                    <!-- Rows added by JS -->
                </tbody>
            </table>
        </div>

        <!-- SQL Preview -->
        <details class="ct-preview" style="margin-top:14px;">
            <summary style="cursor:pointer;font-size:var(--font-size-xs);font-weight:600;color:var(--text-muted);">
                <?= icon('code', 12) ?> SQL Preview
            </summary>
            <div class="code-block" id="ct-sql-preview" style="margin-top:8px;font-size:var(--font-size-xs);min-height:40px;"></div>
        </details>

        <!-- Actions -->
        <div style="display:flex;gap:8px;margin-top:14px;">
            <button type="button" class="btn btn-primary" id="ct-submit">
                <?= icon('plus', 14) ?> Create Table
            </button>
            <button type="button" class="btn btn-ghost" id="ct-cancel">
                <?= icon('x', 14) ?> Cancel
            </button>
        </div>
    </div>
</div>

<!-- MySQL Type Datalist for Create Table -->
<datalist id="ct-types">
    <option value="INT"><option value="INT UNSIGNED"><option value="TINYINT"><option value="TINYINT(1)">
    <option value="SMALLINT"><option value="MEDIUMINT"><option value="BIGINT"><option value="BIGINT UNSIGNED">
    <option value="FLOAT"><option value="DOUBLE"><option value="DECIMAL(10,2)"><option value="DECIMAL(15,2)">
    <option value="VARCHAR(50)"><option value="VARCHAR(100)"><option value="VARCHAR(150)"><option value="VARCHAR(255)">
    <option value="CHAR(1)"><option value="CHAR(36)">
    <option value="TEXT"><option value="TINYTEXT"><option value="MEDIUMTEXT"><option value="LONGTEXT">
    <option value="BLOB"><option value="MEDIUMBLOB"><option value="LONGBLOB">
    <option value="DATE"><option value="DATETIME"><option value="TIMESTAMP"><option value="TIME"><option value="YEAR">
    <option value="BOOLEAN"><option value="ENUM('')"><option value="SET('')"><option value="JSON">
    <option value="BINARY(16)"><option value="VARBINARY(255)">
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('create-table-form');
    var btn = document.getElementById('create-table-btn');
    if (!form || !btn) return;

    var db = '<?= addslashes($currentDb) ?>';
    var csrf = Ledger.getCsrfToken();
    var colIndex = 0;

    // Toggle form
    btn.addEventListener('click', function() {
        form.style.display = '';
        btn.style.display = 'none';
        if (!document.querySelectorAll('#ct-columns-body tr').length) {
            addColumnRow(true); // First column: id, INT, PK, AI
            addColumnRow();
        }
        document.getElementById('ct-name').focus();
    });
    document.getElementById('ct-cancel').addEventListener('click', function() {
        form.style.display = 'none';
        btn.style.display = '';
    });

    // Add column
    document.getElementById('ct-add-col').addEventListener('click', function() { addColumnRow(); });

    function addColumnRow(isFirst) {
        var idx = colIndex++;
        var tr = document.createElement('tr');
        tr.dataset.idx = idx;
        tr.innerHTML =
            '<td><input type="text" class="ct-input ct-col-name" data-idx="'+idx+'" placeholder="column_name" value="'+(isFirst?'id':'')+'"></td>' +
            '<td><input type="text" class="ct-input ct-col-type" data-idx="'+idx+'" list="ct-types" placeholder="VARCHAR(255)" value="'+(isFirst?'INT UNSIGNED':'')+'"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ct-col-null" data-idx="'+idx+'"'+(isFirst?'':' checked')+'></td>' +
            '<td><input type="text" class="ct-input ct-col-default" data-idx="'+idx+'" placeholder="NULL" style="font-size:11px;"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ct-col-pk" data-idx="'+idx+'"'+(isFirst?' checked':'')+'></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ct-col-ai" data-idx="'+idx+'"'+(isFirst?' checked':'')+'></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ct-col-uq" data-idx="'+idx+'"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ct-col-idx" data-idx="'+idx+'"></td>' +
            '<td style="text-align:center;"><button type="button" class="btn btn-danger btn-sm ct-remove-col" style="padding:1px 5px;">×</button></td>';
        document.getElementById('ct-columns-body').appendChild(tr);

        tr.querySelector('.ct-remove-col').addEventListener('click', function() {
            tr.remove();
            updatePreview();
        });

        // Auto-uncheck null when PK is checked
        tr.querySelector('.ct-col-pk').addEventListener('change', function() {
            if (this.checked) tr.querySelector('.ct-col-null').checked = false;
            updatePreview();
        });
        // Auto-check PK when AI is checked
        tr.querySelector('.ct-col-ai').addEventListener('change', function() {
            if (this.checked) {
                tr.querySelector('.ct-col-pk').checked = true;
                tr.querySelector('.ct-col-null').checked = false;
            }
            updatePreview();
        });

        // Update preview on any change
        tr.querySelectorAll('input').forEach(function(inp) {
            inp.addEventListener('input', updatePreview);
            inp.addEventListener('change', updatePreview);
        });

        updatePreview();
    }

    // Build SQL preview
    function buildSql() {
        var name = document.getElementById('ct-name').value.trim();
        var engine = document.getElementById('ct-engine').value;
        var collation = document.getElementById('ct-collation').value;
        var comment = document.getElementById('ct-comment').value.trim();

        if (!name) return '-- Enter a table name';

        var rows = document.querySelectorAll('#ct-columns-body tr');
        if (!rows.length) return '-- Add at least one column';

        var cols = [];
        var pks = [];
        var uqs = [];
        var idxs = [];

        rows.forEach(function(row) {
            var cName = row.querySelector('.ct-col-name').value.trim();
            var cType = row.querySelector('.ct-col-type').value.trim();
            if (!cName || !cType) return;

            var nullable = row.querySelector('.ct-col-null').checked;
            var def = row.querySelector('.ct-col-default').value.trim();
            var pk = row.querySelector('.ct-col-pk').checked;
            var ai = row.querySelector('.ct-col-ai').checked;
            var uq = row.querySelector('.ct-col-uq').checked;
            var idx = row.querySelector('.ct-col-idx').checked;

            var line = '  `' + cName + '` ' + cType;
            line += nullable ? ' NULL' : ' NOT NULL';
            if (ai) line += ' AUTO_INCREMENT';
            else if (def && def.toUpperCase() !== 'NULL') {
                if (def.toUpperCase() === 'CURRENT_TIMESTAMP') line += ' DEFAULT CURRENT_TIMESTAMP';
                else line += " DEFAULT '" + def.replace(/'/g, "\\'") + "'";
            } else if (nullable && !def) {
                line += ' DEFAULT NULL';
            }
            cols.push(line);

            if (pk) pks.push('`' + cName + '`');
            if (uq) uqs.push(cName);
            if (idx) idxs.push(cName);
        });

        if (!cols.length) return '-- Define at least one column';

        if (pks.length) cols.push('  PRIMARY KEY (' + pks.join(', ') + ')');
        uqs.forEach(function(c) { cols.push('  UNIQUE KEY `uq_' + c + '` (`' + c + '`)'); });
        idxs.forEach(function(c) { cols.push('  KEY `idx_' + c + '` (`' + c + '`)'); });

        var sql = 'CREATE TABLE `' + name + '` (\n' + cols.join(',\n') + '\n)';
        sql += ' ENGINE=' + engine;
        sql += ' DEFAULT CHARSET=' + collation.split('_')[0];
        sql += ' COLLATE=' + collation;
        if (comment) sql += " COMMENT='" + comment.replace(/'/g, "\\'") + "'";
        sql += ';';
        return sql;
    }

    function updatePreview() {
        var el = document.getElementById('ct-sql-preview');
        var sql = buildSql();
        // Use tokenizer if available
        if (typeof Ledger !== 'undefined' && Ledger.tokenize) {
            var tokens = Ledger.tokenize(sql);
            tokens = Ledger.resolveTableNames(tokens);
            el.innerHTML = '<code>' + Ledger.renderTokens(tokens) + '</code>';
        } else {
            el.textContent = sql;
        }
    }

    // Watch table options for preview updates
    ['ct-name','ct-engine','ct-collation','ct-comment'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', updatePreview); el.addEventListener('change', updatePreview); }
    });

    // Submit
    document.getElementById('ct-submit').addEventListener('click', function() {
        var sql = buildSql();
        if (sql.startsWith('--')) {
            Ledger.setStatus(sql.replace('-- ', ''));
            return;
        }

        var formData = new FormData();
        formData.append('action', 'execute_sql');
        formData.append('db', db);
        formData.append('sql', sql);
        formData.append('_csrf_token', csrf);

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    Ledger.setStatus('Error: ' + data.error);
                    return;
                }
                var tblName = document.getElementById('ct-name').value.trim();
                Ledger.setStatus('Table "' + tblName + '" created.');
                window.location.href = '?db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tblName) + '&tab=structure';
            })
            .catch(function(err) { Ledger.setStatus('Network error: ' + err.message); });
    });

    // Enter on table name focuses first column name
    document.getElementById('ct-name').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var first = document.querySelector('#ct-columns-body .ct-col-name');
            if (first) first.focus();
        }
    });
});
</script>
<?php endif; ?>

<!-- Table List -->
<div class="table-toolbar">
    <h3 class="section-title" style="margin-bottom:0;">All Tables</h3>
    <span class="toolbar-info"><?= count($allTables) ?> tables</span>
</div>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Table Name</th>
                <th>Engine</th>
                <th>Rows</th>
                <th>Data Size</th>
                <th>Index Size</th>
                <th>Auto Inc.</th>
                <th>Collation</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allTables as $i => $tbl):
                $tName = $tbl['Name'];
                $tRows = $exactCounts[$tName] ?? 0;
                $tData = (int)($tbl['Data_length'] ?? 0);
                $tIdx  = (int)($tbl['Index_length'] ?? 0);
            ?>
            <tr>
                <td>
                    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&tab=browse"
                       style="color:var(--info);font-weight:600;text-decoration:none;">
                        <?= h($tName) ?>
                    </a>
                </td>
                <td style="color:var(--purple);"><?= h($tbl['Engine'] ?? '—') ?></td>
                <td class="cell-number"><?= format_number($tRows) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($tData) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($tIdx) ?></td>
                <td style="color:var(--text-muted);"><?= h($tbl['Auto_increment'] ?? '—') ?></td>
                <td style="color:var(--text-muted);font-size:var(--font-size-xs);"><?= h($tbl['Collation'] ?? '—') ?></td>
                <td>
                    <div style="display:flex;gap:4px;align-items:center;">
                        <?php $rowFav = isset($favSet[$currentDb . '.' . $tName]); ?>
                        <button type="button"
                                class="btn btn-ghost btn-sm table-fav-btn overview-fav-btn <?= $rowFav ? 'is-fav' : '' ?>"
                                data-db="<?= h($currentDb) ?>"
                                data-table="<?= h($tName) ?>"
                                title="<?= $rowFav ? 'Remove from favorites' : 'Add to favorites' ?>"
                                style="padding:2px 6px;">
                            <?= icon($rowFav ? 'star-filled' : 'star', 13) ?>
                        </button>
                        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&tab=browse" class="btn btn-ghost btn-sm" title="Browse"><?= icon('table', 13) ?></a>
                        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&tab=structure" class="btn btn-ghost btn-sm" title="Structure"><?= icon('columns', 13) ?></a>
                        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&tab=export" class="btn btn-ghost btn-sm" title="Export"><?= icon('download', 13) ?></a>
                        <?php if (!$isReadOnly): ?>
                        <button type="button" class="btn btn-ghost btn-sm tbl-rename-btn" data-table="<?= h($tName) ?>" title="Rename"><?= icon('edit', 13) ?></button>
                        <button type="button" class="btn btn-ghost btn-sm tbl-copy-btn" data-table="<?= h($tName) ?>" title="Copy"><?= icon('copy', 13) ?></button>
                        <button type="button" class="btn btn-danger btn-sm tbl-drop-btn" data-table="<?= h($tName) ?>" data-rows="<?= $tRows ?>" title="Drop" style="padding:2px 6px;"><?= icon('trash', 12) ?></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!$isReadOnly): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = '<?= addslashes($currentDb) ?>';
    var csrf = Ledger.getCsrfToken();

    // Rename
    document.querySelectorAll('.tbl-rename-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var oldName = btn.dataset.table;
            Ledger.closeModal();
            var overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'ledger-modal';
            overlay.innerHTML =
                '<div class="modal-box" style="max-width:420px;">' +
                    '<div class="modal-header"><span class="modal-title">Rename Table</span><button class="modal-close" data-action="cancel">&times;</button></div>' +
                    '<div class="modal-body">' +
                        '<div class="settings-field"><label class="settings-label">Current name</label>' +
                        '<input type="text" class="settings-input" value="' + oldName + '" disabled style="opacity:0.5;"></div>' +
                        '<div class="settings-field" style="margin-top:10px;"><label class="settings-label">New name</label>' +
                        '<input type="text" id="modal-rename-input" class="settings-input" value="' + oldName + '" style="background:var(--bg-input);"></div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                        '<button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button>' +
                        '<button class="btn btn-primary modal-btn" id="modal-rename-ok">Rename</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

            var inp = overlay.querySelector('#modal-rename-input');
            inp.focus();
            inp.select();

            function doRename() {
                var newName = inp.value.trim();
                if (!newName || newName === oldName) return;
                var fd = new FormData();
                fd.append('action', 'rename_table');
                fd.append('db', db);
                fd.append('old_name', oldName);
                fd.append('new_name', newName);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                        close();
                        Ledger.setStatus('Table renamed to "' + newName + '".');
                        window.location.href = '?db=' + encodeURIComponent(db);
                    });
            }

            overlay.querySelector('#modal-rename-ok').addEventListener('click', doRename);
            inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') doRename(); });

            function close() {
                overlay.classList.remove('modal-visible');
                setTimeout(function() { overlay.remove(); }, 150);
            }
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay || e.target.dataset.action === 'cancel' || (e.target.closest && e.target.closest('[data-action="cancel"]'))) close();
            });
        });
    });

    // Copy
    document.querySelectorAll('.tbl-copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var source = btn.dataset.table;
            Ledger.closeModal();
            var overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'ledger-modal';
            overlay.innerHTML =
                '<div class="modal-box" style="max-width:420px;">' +
                    '<div class="modal-header"><span class="modal-title">Copy Table</span><button class="modal-close" data-action="cancel">&times;</button></div>' +
                    '<div class="modal-body">' +
                        '<div class="settings-field"><label class="settings-label">Source</label>' +
                        '<input type="text" class="settings-input" value="' + source + '" disabled style="opacity:0.5;"></div>' +
                        '<div class="settings-field" style="margin-top:10px;"><label class="settings-label">New table name</label>' +
                        '<input type="text" id="modal-copy-input" class="settings-input" value="' + source + '_copy" style="background:var(--bg-input);"></div>' +
                        '<div style="margin-top:12px;display:flex;gap:16px;">' +
                            '<label class="settings-check" style="cursor:pointer;"><input type="radio" name="copy_mode" value="data" checked> Structure + Data</label>' +
                            '<label class="settings-check" style="cursor:pointer;"><input type="radio" name="copy_mode" value="structure"> Structure only</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                        '<button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button>' +
                        '<button class="btn btn-primary modal-btn" id="modal-copy-ok">Copy</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

            var inp = overlay.querySelector('#modal-copy-input');
            inp.focus();
            inp.select();

            function doCopy() {
                var dest = inp.value.trim();
                if (!dest) return;
                var withData = overlay.querySelector('input[name="copy_mode"]:checked').value === 'data';
                var fd = new FormData();
                fd.append('action', 'copy_table');
                fd.append('db', db);
                fd.append('source', source);
                fd.append('destination', dest);
                if (withData) fd.append('with_data', '1');
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                        close();
                        Ledger.setStatus('Table copied to "' + dest + '".');
                        window.location.href = '?db=' + encodeURIComponent(db);
                    });
            }

            overlay.querySelector('#modal-copy-ok').addEventListener('click', doCopy);
            inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') doCopy(); });

            function close() {
                overlay.classList.remove('modal-visible');
                setTimeout(function() { overlay.remove(); }, 150);
            }
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay || e.target.dataset.action === 'cancel' || (e.target.closest && e.target.closest('[data-action="cancel"]'))) close();
            });
        });
    });

    // Drop
    document.querySelectorAll('.tbl-drop-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tableName = btn.dataset.table;
            var rows = btn.dataset.rows;
            Ledger.confirm({
                title: 'Drop Table',
                message: 'DROP TABLE `' + tableName + '`?\n\nThis will permanently delete the table structure and all ' + Number(rows).toLocaleString() + ' rows. This cannot be undone.',
                confirmText: 'Drop Table',
                cancelText: 'Cancel',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'drop_table');
                fd.append('db', db);
                fd.append('name', tableName);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                        Ledger.setStatus('Table "' + tableName + '" dropped.');
                        window.location.href = '?db=' + encodeURIComponent(db);
                    });
            });
        });
    });
});
</script>
<?php endif; ?>

<?php
// Views
$dbViews = [];
try { $dbViews = $dbInstance->getViews($currentDb); } catch (Exception $e) {}
?>

<div class="panel-section" style="margin-top:20px;">
    <div class="panel-section-header" style="justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:8px;"><?= icon('eye', 14) ?> Views <span class="db-count"><?= count($dbViews) ?></span></span>
        <?php if (!$isReadOnly): ?>
        <button type="button" class="btn btn-ghost btn-sm" id="view-create-btn" style="padding:2px 8px;font-size:var(--font-size-xs);">
            <?= icon('plus', 12) ?> Create view
        </button>
        <?php endif; ?>
    </div>
    <div class="panel-section-body" style="padding:0;">
        <?php if (empty($dbViews)): ?>
        <div class="panel-empty" style="margin:14px 16px;">
            <?= icon('info', 14) ?>
            <span>No views in this database.</span>
        </div>
        <?php else: ?>
        <div class="trigger-list">
            <?php foreach ($dbViews as $v): ?>
            <div class="trigger-item view-item" data-name="<?= h($v['name']) ?>">
                <div class="trigger-item-head">
                    <?= icon('eye', 13, 'view-icon') ?>
                    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($v['name']) ?>&tab=browse" class="trigger-name" style="text-decoration:none;"><?= h($v['name']) ?></a>
                    <span class="trigger-definer"><?= h($v['definer'] ?? '') ?></span>
                    <?php if (!$isReadOnly): ?>
                    <div class="trigger-actions">
                        <button type="button" class="btn btn-ghost btn-sm view-edit-btn" data-name="<?= h($v['name']) ?>" title="Edit"><?= icon('edit', 12) ?></button>
                        <button type="button" class="btn btn-danger btn-sm view-drop-btn" data-name="<?= h($v['name']) ?>" title="Drop" style="padding:2px 6px;"><?= icon('trash', 12) ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($v['definition'])): ?>
                <pre class="trigger-body view-sql-body"><?= h($v['definition']) ?></pre>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var db = <?= json_encode($currentDb) ?>;

    function openViewModal(mode, name) {
        // If editing, fetch definition first
        if (mode === 'edit' && name) {
            fetch('ajax.php?action=get_view_definition&db=' + encodeURIComponent(db) + '&name=' + encodeURIComponent(name))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                    showModal(mode, name, data.definition || '');
                });
        } else {
            showModal(mode, '', '');
        }
    }

    function showModal(mode, name, definition) {
        Ledger.closeModal();
        // Extract just the SELECT part from full CREATE VIEW statement
        var selectBody = definition;
        if (selectBody) {
            var match = selectBody.match(/\bAS\s+(SELECT\b.+)/is);
            if (match) selectBody = match[1];
        }

        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';
        overlay.innerHTML =
            '<div class="modal-box" style="max-width:640px;">' +
                '<div class="modal-header">' +
                    '<span class="modal-title">' + (mode === 'create' ? 'Create View' : 'Edit View') + '</span>' +
                    '<button class="modal-close" data-action="cancel">&times;</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<div class="settings-field">' +
                        '<label class="settings-label">Name</label>' +
                        '<input type="text" id="vw-name" class="settings-input" value="' + (name || '').replace(/"/g, '&quot;') + '" style="font-family:var(--font-mono);" ' + (mode === 'edit' ? 'disabled' : '') + '>' +
                    '</div>' +
                    '<div class="settings-field" style="margin-top:10px;">' +
                        '<label class="settings-label">Definition <span style="color:var(--text-muted);font-weight:normal;">(SELECT statement)</span></label>' +
                        '<textarea id="vw-def" class="settings-textarea" rows="12" style="font-family:var(--font-mono);font-size:var(--font-size-xs);line-height:1.5;" spellcheck="false" placeholder="SELECT column1, column2 FROM some_table WHERE ...">' + (selectBody || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</textarea>' +
                    '</div>' +
                    '<div id="vw-err" class="error-box" style="margin-top:10px;display:none;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                    '<div id="vw-preview" class="sql-preview" style="margin-top:10px;display:none;">' +
                        '<div class="sql-preview-header"><span class="sql-preview-title">Preview</span></div>' +
                        '<pre class="sql-preview-body" id="vw-preview-body" style="max-height:120px;"></pre>' +
                    '</div>' +
                '</div>' +
                '<div class="modal-footer">' +
                    '<button class="btn btn-ghost modal-btn" id="vw-open-sql" title="Open in SQL Editor with full syntax highlighting and autocomplete"><?= str_replace("'", "\\'", icon('terminal', 12)) ?> Open in SQL Editor</button>' +
                    '<div style="flex:1;"></div>' +
                    '<button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button>' +
                    '<button class="btn btn-primary modal-btn" id="vw-save">' + (mode === 'create' ? 'Create' : 'Save') + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

        // Attach syntax highlighting to the definition textarea
        var defTextarea = overlay.querySelector('#vw-def');
        if (defTextarea && typeof Ledger !== 'undefined' && Ledger.attachHighlighter) {
            Ledger.attachHighlighter(defTextarea);
        }

        var errEl = overlay.querySelector('#vw-err');
        function close() {
            overlay.classList.remove('modal-visible');
            setTimeout(function() { overlay.remove(); }, 150);
        }
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay || (e.target.closest && e.target.closest('[data-action="cancel"]'))) close();
        });

        overlay.querySelector('#vw-save').addEventListener('click', function() {
            errEl.style.display = 'none';
            var vName = overlay.querySelector('#vw-name').value.trim();
            var vDef = overlay.querySelector('#vw-def').value.trim();
            if (!vName || !vDef) { errEl.textContent = 'Name and definition are required.'; errEl.style.display = ''; return; }

            var fd = new FormData();
            fd.append('action', 'create_view');
            fd.append('db', db);
            fd.append('name', vName);
            fd.append('definition', vDef);
            fd.append('_csrf_token', Ledger.getCsrfToken());
            if (mode === 'edit') fd.append('replace', '1');

            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.error) { errEl.textContent = resp.error; errEl.style.display = ''; return; }
                    Ledger.setStatus('View ' + (mode === 'create' ? 'created' : 'updated') + '.');
                    close();
                    window.location.reload();
                });
        });

        // Build full CREATE statement from current fields
        function buildSql() {
            var vName = overlay.querySelector('#vw-name').value.trim() || 'view_name';
            var vDef = overlay.querySelector('#vw-def').value.trim() || 'SELECT 1';
            var prefix = mode === 'edit' ? 'CREATE OR REPLACE' : 'CREATE';
            return prefix + ' VIEW `' + vName + '` AS\n' + vDef + ';';
        }

        // Live preview with syntax highlighting
        var previewEl = overlay.querySelector('#vw-preview');
        var previewBody = overlay.querySelector('#vw-preview-body');
        var defEl = overlay.querySelector('#vw-def');
        var nameEl = overlay.querySelector('#vw-name');

        function updatePreview() {
            var sql = buildSql();
            if (typeof Ledger !== 'undefined' && Ledger.tokenize) {
                var tokens = Ledger.tokenize(sql);
                previewBody.innerHTML = Ledger.renderTokens(tokens);
            } else {
                previewBody.textContent = sql;
            }
            previewEl.style.display = '';
        }

        // Update preview on typing (debounced)
        var previewTimer = null;
        function schedulePreview() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(updatePreview, 200);
        }
        defEl.addEventListener('input', schedulePreview);
        nameEl.addEventListener('input', schedulePreview);
        // Initial preview if there's content
        if (defEl.value.trim()) updatePreview();

        // Open in SQL Editor
        overlay.querySelector('#vw-open-sql').addEventListener('click', function() {
            var sql = buildSql();
            close();
            window.location.href = '?db=' + encodeURIComponent(db) + '&tab=sql&sql=' + encodeURIComponent(sql);
        });
    }

    var createBtn = document.getElementById('view-create-btn');
    if (createBtn) createBtn.addEventListener('click', function() { openViewModal('create'); });

    document.querySelectorAll('.view-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { openViewModal('edit', btn.dataset.name); });
    });

    document.querySelectorAll('.view-drop-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var vName = btn.dataset.name;
            Ledger.confirm({
                title: 'Drop view',
                message: 'Permanently delete view `' + vName + '`?',
                confirmText: 'Drop',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'drop_view');
                fd.append('db', db);
                fd.append('name', vName);
                fd.append('_csrf_token', Ledger.getCsrfToken());
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.error) { Ledger.setStatus('Error: ' + resp.error); return; }
                        Ledger.setStatus('View dropped.');
                        window.location.reload();
                    });
            });
        });
    });

    // Syntax-highlight view SQL definitions
    if (typeof Ledger !== 'undefined' && Ledger.tokenize) {
        document.querySelectorAll('.view-sql-body').forEach(function(el) {
            var tokens = Ledger.tokenize(el.textContent);
            el.innerHTML = Ledger.renderTokens(tokens);
        });
    }
});
</script>

<?php return; endif; ?>

<?php
$page    = max(1, (int) input('page', 1));
$perPage = (int) ($config['app']['rows_per_page'] ?? 50);
$orderBy = input('sort');
$orderDir = input('dir', 'ASC');
$search  = input('search', '');
$fkCol   = input('fk_col', '');
$fkVal   = input('fk_val', '');

try {
    $columns = $dbInstance->getColumns($currentDb, $currentTable);
    if ($fkCol !== '' && $fkVal !== '') {
        // Exact-match FK drill-down filter
        $result = $dbInstance->browseTable($currentDb, $currentTable, $page, $perPage, $orderBy, $orderDir, null, $fkCol, $fkVal);
    } else {
        $result = $dbInstance->browseTable($currentDb, $currentTable, $page, $perPage, $orderBy, $orderDir, $search ?: null);
    }
} catch (Exception $e) {
    echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
    return;
}

$rows       = $result['rows'];
$total      = $result['total'];
$totalPages = $result['total_pages'];
$browseSql  = $result['sql'] ?? '';

// Build column lookup for key info
$colInfo = [];
foreach ($columns as $col) {
    $colInfo[$col['Field']] = $col;
}

// Build FK drill-down map: column_name → [ref_table, ref_column, ref_schema]
$fkMap = [];
try {
    $fks = $dbInstance->getForeignKeys($currentDb, $currentTable);
    foreach ($fks as $fk) {
        $fkMap[$fk['COLUMN_NAME']] = [
            'table'  => $fk['REFERENCED_TABLE_NAME'],
            'column' => $fk['REFERENCED_COLUMN_NAME'],
            'schema' => $fk['REFERENCED_TABLE_SCHEMA'],
        ];
    }
} catch (Exception $e) {
    // Non-critical — just skip FK links
}
?>

<!-- Current Query -->
<div class="browse-query">
    <code class="browse-query-sql" id="browse-query-sql"><?= h($browseSql) ?></code>
    <a href="?db=<?= urlencode($currentDb) ?>&tab=sql&sql=<?= urlencode($browseSql) ?>&run=1" class="browse-query-edit" title="Edit in SQL tab"><?= icon('edit', 11) ?> Edit</a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var el = document.getElementById('browse-query-sql');
    if (el && typeof Ledger !== 'undefined' && Ledger.tokenize) {
        var tokens = Ledger.tokenize(el.textContent);
        tokens = Ledger.resolveTableNames(tokens);
        el.innerHTML = Ledger.renderTokens(tokens);
    }
});
</script>

<!-- Toolbar -->
<?php if ($fkCol && $fkVal !== ''): ?>
<div class="fk-filter-banner">
    <?= icon('share', 13) ?>
    <span>Showing rows where <code><?= h($fkCol) ?></code> = <code><?= h(truncate($fkVal, 40)) ?></code></span>
    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse" class="btn btn-ghost btn-sm"><?= icon('x', 12) ?> Clear filter</a>
</div>
<?php endif; ?>
<div class="table-toolbar">
    <form method="get" class="search-box">
        <input type="hidden" name="db" value="<?= h($currentDb) ?>">
        <input type="hidden" name="table" value="<?= h($currentTable) ?>">
        <input type="hidden" name="tab" value="browse">
        <?= icon("search", 14) ?>
        <input type="text" name="search" class="search-input" placeholder="Filter rows…" value="<?= h($search) ?>">
    </form>
    <div class="toolbar-info">
        <span>Showing <?= format_number(count($rows)) ?> of <?= format_number($total) ?> rows</span>
        <span>|</span>
        <span>Page <?= $page ?>/<?= max(1, $totalPages) ?></span>
    </div>
    <div class="toolbar-actions">
        <?php
            $favUser = (isset($auth) && $auth->isLoggedIn()) ? $auth->getUsername() : 'anonymous';
            $isFavTable = ledger_favorites_has($favUser, $currentDb, $currentTable);
        ?>
        <button type="button"
                class="btn btn-ghost btn-sm browse-fav-btn <?= $isFavTable ? 'is-fav' : '' ?>"
                data-db="<?= h($currentDb) ?>"
                data-table="<?= h($currentTable) ?>"
                title="<?= $isFavTable ? 'Remove from favorites' : 'Add to favorites' ?>">
            <?= icon($isFavTable ? 'star-filled' : 'star', 13) ?>
            <?= $isFavTable ? 'Favorited' : 'Favorite' ?>
        </button>
        <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
        <button type="button" class="btn btn-primary btn-sm" id="insert-row-btn">
            <?= icon('plus', 13) ?> Insert Row
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm" id="toggle-col-types" onclick="Ledger.toggleColTypes()" title="Toggle column types">
            <?= icon('eye', 13) ?> Types
        </button>
        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=export" class="btn btn-ghost btn-sm"><?= icon("download", 13) ?> Export</a>
    </div>
</div>

<?php
// Find primary key column for inline editing + bulk select
$pkCol = null;
foreach ($columns as $col) {
    if ($col['Key'] === 'PRI') { $pkCol = $col['Field']; break; }
}
?>

<!-- Insert Row Form (hidden by default) -->
<?php if (!(isset($auth) && $auth->isReadOnly())): ?>
<div id="insert-row-form" style="display:none;" class="insert-row-form">
    <div class="insert-row-card">
        <div class="insert-row-header">
            <?= icon('plus', 16) ?>
            <span>Insert Row into <strong><?= h($currentTable) ?></strong></span>
            <button type="button" class="btn btn-ghost btn-sm" id="insert-row-cancel" style="margin-left:auto;"><?= icon('x', 12) ?> Close</button>
        </div>
        <div class="insert-row-body">
            <?php foreach ($columns as $col):
                $field = $col['Field'];
                $type = strtolower($col['Type']);
                $isAI = stripos($col['Extra'], 'auto_increment') !== false;
                $isNullable = $col['Null'] === 'YES';
                $hasDefault = $col['Default'] !== null;
                $defaultVal = $col['Default'] ?? '';
                $isPK = $col['Key'] === 'PRI';

                // Determine input type
                $inputType = 'text';
                $isTextarea = false;
                $placeholder = $type;
                if (preg_match('/^(text|mediumtext|longtext|tinytext|blob|mediumblob|longblob|json)/', $type)) {
                    $isTextarea = true;
                } elseif (preg_match('/^(int|smallint|mediumint|bigint|tinyint)/', $type)) {
                    $inputType = 'number';
                    $placeholder = $isAI ? 'AUTO' : '0';
                } elseif (preg_match('/^(float|double|decimal|numeric)/', $type)) {
                    $inputType = 'number';
                    $placeholder = '0.00';
                } elseif (preg_match('/^date$/', $type)) {
                    $inputType = 'date';
                    $placeholder = 'YYYY-MM-DD';
                } elseif (preg_match('/^datetime|^timestamp/', $type)) {
                    $inputType = 'datetime-local';
                    $placeholder = 'YYYY-MM-DD HH:MM:SS';
                } elseif (preg_match('/^time$/', $type)) {
                    $inputType = 'time';
                    $placeholder = 'HH:MM:SS';
                } elseif (preg_match('/^year/', $type)) {
                    $inputType = 'number';
                    $placeholder = date('Y');
                } elseif (preg_match('/^enum\((.+)\)/', $type, $enumMatch)) {
                    $inputType = 'enum';
                }
            ?>
            <div class="insert-field" data-col="<?= h($field) ?>">
                <div class="insert-field-label">
                    <span class="insert-field-name"><?= h($field) ?></span>
                    <span class="insert-field-type"><?= h($col['Type']) ?></span>
                    <div class="insert-field-badges">
                        <?php if ($isPK): ?><span class="key-badge key-badge-pk" style="font-size:9px;padding:0 4px;"><?= icon('key', 9) ?> PK</span><?php endif; ?>
                        <?php if ($isAI): ?><span class="insert-field-ai"><?= icon('zap', 10) ?> AI</span><?php endif; ?>
                    </div>
                </div>
                <div class="insert-field-input">
                    <?php if ($inputType === 'enum'):
                        preg_match_all("/'([^']+)'/", $col['Type'], $enumVals);
                    ?>
                    <select class="insert-input" data-col="<?= h($field) ?>" <?= $isAI ? 'disabled' : '' ?>>
                        <?php if ($isNullable): ?><option value="__NULL__">NULL</option><?php endif; ?>
                        <?php foreach ($enumVals[1] ?? [] as $ev): ?>
                        <option value="<?= h($ev) ?>" <?= $defaultVal === $ev ? 'selected' : '' ?>><?= h($ev) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($isTextarea): ?>
                    <textarea class="insert-input insert-input-large" data-col="<?= h($field) ?>"
                              placeholder="<?= h($placeholder) ?>" <?= $isAI ? 'disabled' : '' ?>><?= $hasDefault ? h($defaultVal) : '' ?></textarea>
                    <?php else: ?>
                    <input type="<?= $inputType ?>" class="insert-input" data-col="<?= h($field) ?>"
                           placeholder="<?= h($placeholder) ?>"
                           value="<?= $hasDefault && !$isAI ? h($defaultVal) : '' ?>"
                           <?= $isAI ? 'disabled' : '' ?>
                           <?= $inputType === 'number' ? 'step="any"' : '' ?>>
                    <?php endif; ?>

                    <div class="insert-field-options">
                        <?php if ($isAI): ?>
                        <label class="insert-field-check" title="Auto-generated. Check to set manually.">
                            <input type="checkbox" class="insert-ai-override" data-col="<?= h($field) ?>">
                            <span>Manual</span>
                        </label>
                        <?php endif; ?>
                        <?php if ($isNullable && $inputType !== 'enum'): ?>
                        <label class="insert-field-check">
                            <input type="checkbox" class="insert-null-check" data-col="<?= h($field) ?>" <?= (!$hasDefault && !$isAI) ? 'checked' : '' ?>>
                            <span>NULL</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="insert-row-footer">
            <button type="button" class="btn btn-primary" id="insert-row-submit">
                <?= icon('plus', 14) ?> Insert Row
            </button>
            <label class="insert-field-check">
                <input type="checkbox" id="insert-another" checked>
                <span>Insert another after</span>
            </label>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var formEl = document.getElementById('insert-row-form');
    var btn = document.getElementById('insert-row-btn');
    if (!formEl || !btn) return;

    var db = '<?= addslashes($currentDb) ?>';
    var table = '<?= addslashes($currentTable) ?>';
    var csrf = Ledger.getCsrfToken();

    // Toggle
    btn.addEventListener('click', function() {
        formEl.style.display = '';
        btn.style.display = 'none';
        var firstInput = formEl.querySelector('.insert-input:not([disabled])');
        if (firstInput) firstInput.focus();
    });
    document.getElementById('insert-row-cancel').addEventListener('click', function() {
        formEl.style.display = 'none';
        btn.style.display = '';
    });

    // AI override toggles
    formEl.querySelectorAll('.insert-ai-override').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var col = cb.dataset.col;
            var input = formEl.querySelector('.insert-input[data-col="' + col + '"]');
            if (input) {
                input.disabled = !cb.checked;
                if (!cb.checked) input.value = '';
            }
        });
    });

    // NULL checkbox toggles
    formEl.querySelectorAll('.insert-null-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var col = cb.dataset.col;
            var input = formEl.querySelector('.insert-input[data-col="' + col + '"]');
            if (input) {
                input.disabled = cb.checked;
                if (cb.checked) { if (input.tagName === 'TEXTAREA') input.value = ''; else input.value = ''; }
                else input.focus();
            }
        });
        // Apply initial state
        if (cb.checked) {
            var col = cb.dataset.col;
            var input = formEl.querySelector('.insert-input[data-col="' + col + '"]');
            if (input) input.disabled = true;
        }
    });

    // Submit
    document.getElementById('insert-row-submit').addEventListener('click', function() {
        var data = {};
        var fields = formEl.querySelectorAll('.insert-field');

        fields.forEach(function(f) {
            var col = f.dataset.col;
            var input = f.querySelector('.insert-input');
            var nullCb = f.querySelector('.insert-null-check');
            var aiCb = f.querySelector('.insert-ai-override');
            var isAI = !!aiCb;

            // Skip AI columns unless manually set
            if (isAI && !aiCb.checked) return;
            // NULL
            if (nullCb && nullCb.checked) { data[col] = null; return; }
            // Disabled (shouldn't happen outside AI)
            if (input.disabled) return;

            var val = input.value;
            // Enum NULL option
            if (input.tagName === 'SELECT' && val === '__NULL__') { data[col] = null; return; }

            data[col] = val;
        });

        var formData = new FormData();
        formData.append('action', 'insert_row');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('data', JSON.stringify(data));
        formData.append('_csrf_token', csrf);

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.error) {
                    Ledger.setStatus('Insert error: ' + result.error);
                    return;
                }
                Ledger.setStatus('Row inserted' + (result.insert_id ? ' (ID: ' + result.insert_id + ')' : '') + '.');

                if (document.getElementById('insert-another').checked) {
                    // Clear non-default, non-AI fields
                    fields.forEach(function(f) {
                        var input = f.querySelector('.insert-input');
                        var aiCb = f.querySelector('.insert-ai-override');
                        if (aiCb || input.disabled) return;
                        if (input.tagName === 'SELECT') return;
                        if (input.dataset.hasDefault) return;
                        input.value = '';
                    });
                    var firstInput = formEl.querySelector('.insert-input:not([disabled])');
                    if (firstInput) firstInput.focus();
                } else {
                    window.location.reload();
                }
            })
            .catch(function(err) { Ledger.setStatus('Network error: ' + err.message); });
    });

    // Enter key submits (except in textarea)
    formEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            document.getElementById('insert-row-submit').click();
        }
    });
});
</script>
<?php endif; ?>

<!-- Bulk Actions Bar (hidden until rows selected) -->
<?php if ($pkCol && !empty($rows)): ?>
<div class="bulk-bar" id="bulk-bar" style="display:none;">
    <label class="bulk-count" id="bulk-count">0 rows selected</label>
    <button type="button" class="btn btn-danger btn-sm" id="bulk-delete-btn">
        <?= icon('trash', 13) ?> Delete Selected
    </button>
    <button type="button" class="btn btn-ghost btn-sm" id="bulk-clear-btn">
        <?= icon('x', 13) ?> Clear
    </button>
</div>
<?php endif; ?>

<!-- Data Table -->
<div class="table-wrapper"
     id="browse-table"
     data-db="<?= h($currentDb) ?>"
     data-table="<?= h($currentTable) ?>"
     data-pk="<?= h($pkCol ?? '') ?>">
    <table class="data-table">
        <thead>
            <tr>
                <?php if ($pkCol): ?>
                <th style="width:36px;text-align:center;padding:6px;">
                    <input type="checkbox" id="select-all" class="row-checkbox" title="Select all">
                </th>
                <?php endif; ?>
                <?php foreach ($columns as $col): ?>
                <?php
                    $field = $col['Field'];
                    $isSorted = ($orderBy === $field);
                    $nextDir = ($isSorted && $orderDir === 'ASC') ? 'DESC' : 'ASC';
                    $sortUrl = "?db=" . urlencode($currentDb) . "&table=" . urlencode($currentTable) . "&tab=browse&sort=" . urlencode($field) . "&dir={$nextDir}" . ($search ? "&search=" . urlencode($search) : '');
                ?>
                <th>
                    <a href="<?= $sortUrl ?>" style="color:inherit;text-decoration:none;display:block;">
                        <div style="display:flex;align-items:center;gap:4px;">
                            <?= h($field) ?>
                            <?php if ($col['Key'] === 'PRI'): ?>
                            <span class="key-icon" title="Primary Key"><?= icon('key', 12) ?></span>
                            <?php endif; ?>
                            <?php if (isset($fkMap[$field])): ?>
                            <span class="fk-icon" title="FK → <?= h($fkMap[$field]['table']) ?>.<?= h($fkMap[$field]['column']) ?>"><?= icon('share', 11) ?></span>
                            <?php endif; ?>
                            <?php if ($isSorted): ?>
                            <span class="sort-icon"><?= icon($orderDir === 'ASC' ? 'arrow-up' : 'arrow-down', 11) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="col-type"><?= h($col['Type']) ?></div>
                    </a>
                </th>
                <?php endforeach; ?>
                <?php if ($pkCol): ?>
                <th style="width:40px;text-align:center;">⋯</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?= count($columns) + ($pkCol ? 2 : 0) ?>">
                    <div class="empty-state">
                        <?php if ($search): ?>
                            <?= icon('search', 28) ?>
                            <div class="empty-state-title">No results</div>
                            <div class="empty-state-desc">No rows match "<strong><?= h($search) ?></strong>" in this table.</div>
                            <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=browse" class="btn btn-ghost btn-sm" style="margin-top:8px;"><?= icon('x', 12) ?> Clear filter</a>
                        <?php else: ?>
                            <?= icon('table', 28) ?>
                            <div class="empty-state-title">This table is empty</div>
                            <div class="empty-state-desc"><?= h($currentTable) ?> has no rows yet.</div>
                            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top:8px;" onclick="document.getElementById('insert-row-btn')?.click();"><?= icon('plus', 12) ?> Insert first row</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($rows as $ri => $row): ?>
            <?php $pkVal = $pkCol ? ($row[$pkCol] ?? '') : ''; ?>
            <tr data-pk-val="<?= h(strval($pkVal)) ?>" data-row="<?= h(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" class="browse-row">
                <?php if ($pkCol): ?>
                <td style="text-align:center;padding:4px 6px;">
                    <input type="checkbox" class="row-checkbox row-select" data-pk="<?= h(strval($pkVal)) ?>">
                </td>
                <?php endif; ?>
                <?php foreach ($columns as $col): ?>
                <?php
                    $field = $col['Field'];
                    $value = $row[$field] ?? null;
                    $cls = cell_class($value, $col['Key']);
                    $isEditable = ($pkCol && $col['Key'] !== 'PRI');
                    $hasFk = isset($fkMap[$field]) && $value !== null;
                ?>
                <td class="<?= $cls ?><?= $isEditable ? ' cell-editable' : '' ?><?= $hasFk ? ' cell-fk' : '' ?>"
                    <?php if ($isEditable): ?>
                    data-col="<?= h($field) ?>"
                    data-value="<?= h($value !== null ? strval($value) : '') ?>"
                    data-null="<?= $value === null ? '1' : '0' ?>"
                    <?php endif; ?>
                >
                    <?php if ($value === null): ?>
                        <span class="cell-null">NULL</span>
                    <?php elseif ($hasFk): ?>
                        <?php
                            $ref = $fkMap[$field];
                            $refDb = ($ref['schema'] !== $currentDb) ? $ref['schema'] : $currentDb;
                            $fkUrl = '?db=' . urlencode($refDb)
                                   . '&table=' . urlencode($ref['table'])
                                   . '&tab=browse'
                                   . '&fk_col=' . urlencode($ref['column'])
                                   . '&fk_val=' . urlencode($value);
                        ?>
                        <a href="<?= h($fkUrl) ?>" class="fk-link" title="Go to <?= h($ref['table']) ?>.<?= h($ref['column']) ?> = <?= h(truncate(strval($value), 30)) ?>">
                            <?= h(truncate(strval($value), 80)) ?>
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-left:3px;opacity:0.5;"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                    <?php elseif ($cls === 'cell-hash'): ?>
                        <?= h(truncate($value, 20)) ?>
                    <?php else: ?>
                        <?= h(truncate(strval($value), 80)) ?>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <?php if ($pkCol): ?>
                <td style="text-align:center;padding:4px;white-space:nowrap;">
                    <button class="btn btn-ghost btn-sm row-detail-btn"
                            title="View row detail"
                            style="padding:2px 6px;font-size:11px;"><?= icon('eye', 12) ?></button>
                    <button class="btn btn-danger btn-sm row-delete-btn"
                            data-pk="<?= h(strval($pkVal)) ?>"
                            title="Delete row"
                            style="padding:2px 6px;font-size:11px;"><?= icon('trash', 12) ?></button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $baseUrl = "?db=" . urlencode($currentDb) . "&table=" . urlencode($currentTable) . "&tab=browse"
        . ($orderBy ? "&sort=" . urlencode($orderBy) . "&dir={$orderDir}" : '')
        . ($search ? "&search=" . urlencode($search) : '');
    ?>
    <a href="<?= $baseUrl ?>&page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">« First</a>
    <a href="<?= $baseUrl ?>&page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹ Prev</a>

    <?php
    $start = max(1, $page - 3);
    $end = min($totalPages, $page + 3);
    for ($p = $start; $p <= $end; $p++):
    ?>
    <a href="<?= $baseUrl ?>&page=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>

    <a href="<?= $baseUrl ?>&page=<?= min($totalPages, $page + 1) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next ›</a>
    <a href="<?= $baseUrl ?>&page=<?= $totalPages ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Last »</a>
</div>
<?php endif; ?>

<!-- Row Detail Panel -->
<div class="row-detail-overlay" id="row-detail-overlay" style="display:none;">
    <div class="row-detail-panel" id="row-detail-panel">
        <div class="row-detail-header">
            <span class="row-detail-title"><?= icon('eye', 14) ?> Row Detail</span>
            <button type="button" class="row-detail-close" id="row-detail-close">&times;</button>
        </div>
        <div class="row-detail-body" id="row-detail-body"></div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var overlay = document.getElementById('row-detail-overlay');
    var panel = document.getElementById('row-detail-panel');
    var body = document.getElementById('row-detail-body');
    var closeBtn = document.getElementById('row-detail-close');
    if (!overlay || !body) return;

    var columns = <?= json_encode(array_map(fn($c) => ['Field' => $c['Field'], 'Type' => $c['Type'], 'Key' => $c['Key']], $columns)) ?>;
    var fkMap = <?= json_encode($fkMap) ?>;
    var currentDb = <?= json_encode($currentDb) ?>;

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function showDetail(rowData) {
        var html = '<table class="row-detail-table">';
        columns.forEach(function(col) {
            var field = col.Field;
            var val = rowData[field];
            var isNull = val === null;
            var isPK = col.Key === 'PRI';
            var hasFk = fkMap[field] && !isNull;

            html += '<tr>';
            html += '<td class="row-detail-field' + (isPK ? ' is-pk' : '') + '">';
            if (isPK) html += '<?= str_replace("'", "\\'", icon('key', 10)) ?> ';
            if (hasFk) html += '<?= str_replace("'", "\\'", icon('share', 10)) ?> ';
            html += escapeHtml(field);
            html += '<span class="row-detail-type">' + escapeHtml(col.Type) + '</span>';
            html += '</td>';

            html += '<td class="row-detail-value">';
            if (isNull) {
                html += '<span class="cell-null">NULL</span>';
            } else if (hasFk) {
                var ref = fkMap[field];
                var refDb = ref.schema !== currentDb ? ref.schema : currentDb;
                var url = '?db=' + encodeURIComponent(refDb) + '&table=' + encodeURIComponent(ref.table) + '&tab=browse&fk_col=' + encodeURIComponent(ref.column) + '&fk_val=' + encodeURIComponent(val);
                html += '<a href="' + escapeHtml(url) + '" class="fk-link">' + escapeHtml(String(val)) + ' → ' + escapeHtml(ref.table) + '</a>';
            } else {
                html += '<span class="row-detail-val-text">' + escapeHtml(String(val)) + '</span>';
            }
            html += '</td>';
            html += '</tr>';
        });
        html += '</table>';
        body.innerHTML = html;
        overlay.style.display = '';
        requestAnimationFrame(function() { overlay.classList.add('visible'); });
    }

    function closeDetail() {
        overlay.classList.remove('visible');
        setTimeout(function() { overlay.style.display = 'none'; }, 150);
    }

    // Click eye button to open detail
    document.querySelectorAll('.row-detail-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var tr = btn.closest('tr.browse-row');
            if (!tr) return;
            var data = tr.dataset.row;
            if (!data) return;
            try { showDetail(JSON.parse(data)); } catch(err) {}
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeDetail);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeDetail();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display !== 'none') closeDetail();
    });
});
</script>
