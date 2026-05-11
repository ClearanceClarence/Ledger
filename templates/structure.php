<?php if (!$currentDb || !$currentTable): ?>
<div class="error-box">Select a table to view its structure.</div>
<?php return; endif; ?>

<?php
try {
    $columns = $dbInstance->getColumns($currentDb, $currentTable);
    $indexes = $dbInstance->getIndexes($currentDb, $currentTable);
    $createSql = $dbInstance->getCreateStatement($currentDb, $currentTable);

    // Group indexes by Key_name (composite indexes span multiple rows in SHOW INDEX)
    $indexGroups = [];
    foreach ($indexes as $idx) {
        $kn = $idx['Key_name'];
        if (!isset($indexGroups[$kn])) {
            $indexGroups[$kn] = [
                'Key_name'     => $kn,
                'Non_unique'   => $idx['Non_unique'],
                'Index_type'   => $idx['Index_type'],
                'Cardinality'  => $idx['Cardinality'],
                'columns'      => [],
            ];
        }
        $indexGroups[$kn]['columns'][] = [
            'Column_name' => $idx['Column_name'],
            'Seq_in_index' => $idx['Seq_in_index'],
            'Sub_part'    => $idx['Sub_part'],
        ];
    }
    // Sort columns within each group by sequence
    foreach ($indexGroups as &$g) {
        usort($g['columns'], function($a, $b) { return (int)$a['Seq_in_index'] - (int)$b['Seq_in_index']; });
    }
    unset($g);

    // Which index kind is each one? PRIMARY / UNIQUE / FULLTEXT / SPATIAL / INDEX
    foreach ($indexGroups as &$g) {
        if ($g['Key_name'] === 'PRIMARY') {
            $g['kind'] = 'PRIMARY';
        } elseif (strtoupper($g['Index_type']) === 'FULLTEXT') {
            $g['kind'] = 'FULLTEXT';
        } elseif (strtoupper($g['Index_type']) === 'SPATIAL') {
            $g['kind'] = 'SPATIAL';
        } elseif (!$g['Non_unique']) {
            $g['kind'] = 'UNIQUE';
        } else {
            $g['kind'] = 'INDEX';
        }
    }
    unset($g);

    // Foreign keys
    $foreignKeys = [];
    $referencedBy = [];
    try {
        $foreignKeys = $dbInstance->getForeignKeys($currentDb, $currentTable);
        $referencedBy = $dbInstance->getReferencedBy($currentDb, $currentTable);
    } catch (Exception $e) {
        // FK queries may fail on some MySQL configs - not critical
    }

    // Build lookup: column → FK info
    $fkByColumn = [];
    foreach ($foreignKeys as $fk) {
        $fkByColumn[$fk['COLUMN_NAME']] = $fk;
    }

    // Find primary key columns
    $pkColumns = [];
    foreach ($columns as $col) {
        if ($col['Key'] === 'PRI') $pkColumns[] = $col['Field'];
    }

    // Get table status for AUTO_INCREMENT value
    $tableStatus = $dbInstance->getTables($currentDb);
    $autoIncValue = null;
    $autoIncCol = null;
    foreach ($tableStatus as $ts) {
        if ($ts['Name'] === $currentTable) {
            $autoIncValue = $ts['Auto_increment'] ?? null;
            break;
        }
    }
    // Find which column is AUTO_INCREMENT
    foreach ($columns as $col) {
        if (stripos($col['Extra'], 'auto_increment') !== false) {
            $autoIncCol = $col['Field'];
            break;
        }
    }
} catch (Exception $e) {
    echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
    return;
}

$isReadOnly = isset($auth) && $auth->isReadOnly();
$csrfToken = isset($auth) ? $auth->generateCsrfToken() : '';
?>

<div id="structure-editor"
     data-db="<?= h($currentDb) ?>"
     data-table="<?= h($currentTable) ?>"
     data-csrf="<?= h($csrfToken) ?>"
     data-readonly="<?= $isReadOnly ? '1' : '0' ?>">

<?php if ($autoIncValue !== null && $autoIncCol): ?>
<!-- Auto Increment Info -->
<div class="auto-inc-bar">
    <div class="auto-inc-info">
        <?= icon('zap', 15) ?>
        <span class="auto-inc-label">AUTO_INCREMENT</span>
        <span class="auto-inc-col"><?= h($autoIncCol) ?></span>
        <span class="auto-inc-sep">→</span>
        <span class="auto-inc-value">Next ID: <strong><?= format_number($autoIncValue) ?></strong></span>
    </div>
    <?php if (!$isReadOnly): ?>
    <div class="auto-inc-actions" id="auto-inc-actions">
        <input type="number" id="auto-inc-input" class="auto-inc-input"
               value="<?= (int)$autoIncValue ?>" min="1" title="Set next AUTO_INCREMENT value">
        <button type="button" class="btn btn-ghost btn-sm" id="auto-inc-set-btn" title="Set value">
            <?= icon('check', 12) ?> Set
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="auto-inc-reset-btn" title="Reset to max(id)+1">
            <?= icon('refresh', 12) ?> Reset
        </button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Columns -->
<div class="table-toolbar">
    <h3 class="section-title" style="margin-bottom:0;">
        <?= icon('columns', 16) ?> Columns — <span class="highlight"><?= h($currentTable) ?></span>
    </h3>
    <?php if (!$isReadOnly): ?>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-primary btn-sm" id="add-column-btn">
            <?= icon('plus', 13) ?> Add Column
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="table-wrapper">
    <table class="data-table" id="structure-table">
        <thead>
            <tr>
                <th>Column</th>
                <th>Type</th>
                <th>Null</th>
                <th>Key</th>
                <th>Default</th>
                <th>Extra</th>
                <th>Collation</th>
                <th>Comment</th>
                <?php if (!$isReadOnly): ?>
                <th style="width:80px;text-align:center;">Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($columns as $i => $col): ?>
            <?php
                $isPK = $col['Key'] === 'PRI';
                $isFK = isset($fkByColumn[$col['Field']]);
                $fkInfo = $isFK ? $fkByColumn[$col['Field']] : null;
                $rowClass = 'struct-row';
                if ($isPK) $rowClass .= ' struct-row-pk';
                if ($isFK) $rowClass .= ' struct-row-fk';
            ?>
            <tr data-original-name="<?= h($col['Field']) ?>" class="<?= $rowClass ?>">
                <!-- Column Name -->
                <td class="struct-cell" data-field="name">
                    <span class="struct-display struct-name-display">
                        <?php if ($isPK): ?>
                        <span class="struct-key-icon struct-pk-icon" title="Primary Key"><?= icon('key', 13) ?></span>
                        <?php endif; ?>
                        <?php if ($isFK): ?>
                        <span class="struct-key-icon struct-fk-icon" title="Foreign Key → <?= h($fkInfo['REFERENCED_TABLE_NAME'] . '.' . $fkInfo['REFERENCED_COLUMN_NAME']) ?>"><?= icon('external-link', 13) ?></span>
                        <?php endif; ?>
                        <span style="font-weight:600;color:var(--text-primary);"><?= h($col['Field']) ?></span>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <input type="text" class="struct-input" value="<?= h($col['Field']) ?>" style="display:none;">
                    <?php endif; ?>
                </td>

                <!-- Type (searchable) -->
                <td class="struct-cell" data-field="type">
                    <span class="struct-display" style="color:var(--purple);font-family:var(--font-mono);">
                        <?= h($col['Type']) ?>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <input type="text" class="struct-input struct-type-input" list="mysql-types"
                           value="<?= h($col['Type']) ?>" style="display:none;"
                           placeholder="e.g. VARCHAR(255)">
                    <?php endif; ?>
                </td>

                <!-- Null -->
                <td class="struct-cell" data-field="null">
                    <span class="struct-display">
                        <?php if ($col['Null'] === 'YES'): ?>
                        <span class="badge badge-nullable">YES</span>
                        <?php else: ?>
                        <span class="badge badge-yes">NO</span>
                        <?php endif; ?>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <label class="struct-check" style="display:none;">
                        <input type="checkbox" class="struct-null-check" <?= $col['Null'] === 'YES' ? 'checked' : '' ?>>
                        <span>Allow NULL</span>
                    </label>
                    <?php endif; ?>
                </td>

                <!-- Key -->
                <td class="struct-key-cell">
                    <?php if ($isPK): ?>
                    <span class="key-badge key-badge-pk"><?= icon('key', 11) ?> PK</span>
                    <?php endif; ?>
                    <?php if ($isFK): ?>
                    <a href="?db=<?= urlencode($fkInfo['REFERENCED_TABLE_SCHEMA'] ?? $currentDb) ?>&table=<?= urlencode($fkInfo['REFERENCED_TABLE_NAME']) ?>&tab=structure"
                       class="key-badge key-badge-fk" title="<?= h($fkInfo['CONSTRAINT_NAME']) ?>">
                        <?= icon('external-link', 11) ?> FK → <?= h($fkInfo['REFERENCED_TABLE_NAME']) ?>.<?= h($fkInfo['REFERENCED_COLUMN_NAME']) ?>
                    </a>
                    <?php elseif ($col['Key'] === 'UNI'): ?>
                    <span class="key-badge key-badge-uni"><?= icon('hash', 11) ?> UNI</span>
                    <?php elseif ($col['Key'] === 'MUL' && !$isFK): ?>
                    <span class="key-badge key-badge-idx"><?= icon('list', 11) ?> IDX</span>
                    <?php endif; ?>
                </td>

                <!-- Default -->
                <td class="struct-cell" data-field="default">
                    <span class="struct-display" style="font-family:var(--font-mono);">
                        <?php if ($col['Default'] !== null): ?>
                        <span style="color:var(--text-secondary);"><?= h($col['Default']) ?></span>
                        <?php else: ?>
                        <span class="cell-null">NULL</span>
                        <?php endif; ?>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <div class="struct-default-wrap" style="display:none;">
                        <input type="text" class="struct-input struct-default-input"
                               value="<?= h($col['Default'] ?? '') ?>"
                               placeholder="Default value"
                               <?= $col['Default'] === null ? 'disabled' : '' ?>>
                        <label class="struct-check-mini">
                            <input type="checkbox" class="struct-default-null" <?= $col['Default'] === null ? 'checked' : '' ?>>
                            NULL
                        </label>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Extra -->
                <td class="struct-cell" data-field="extra">
                    <span class="struct-display" style="color:var(--info);">
                        <?= h($col['Extra']) ?>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <select class="struct-input struct-extra-select" style="display:none;">
                        <option value="" <?= empty($col['Extra']) ? 'selected' : '' ?>>—</option>
                        <option value="AUTO_INCREMENT" <?= $col['Extra'] === 'auto_increment' ? 'selected' : '' ?>>AUTO_INCREMENT</option>
                        <option value="on update CURRENT_TIMESTAMP" <?= str_contains($col['Extra'], 'on update') ? 'selected' : '' ?>>ON UPDATE CURRENT_TIMESTAMP</option>
                    </select>
                    <?php endif; ?>
                </td>

                <!-- Collation -->
                <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-muted);">
                    <?= h($col['Collation'] ?? '') ?: '<span style="opacity:0.3">—</span>' ?>
                </td>

                <!-- Comment -->
                <td class="struct-cell" data-field="comment">
                    <span class="struct-display" style="color:var(--text-muted);font-size:var(--font-size-xs);">
                        <?= h($col['Comment'] ?? '') ?: '<span style="opacity:0.3">—</span>' ?>
                    </span>
                    <?php if (!$isReadOnly): ?>
                    <input type="text" class="struct-input struct-comment-input"
                           value="<?= h($col['Comment'] ?? '') ?>" style="display:none;"
                           placeholder="Add comment...">
                    <?php endif; ?>
                </td>

                <!-- Actions -->
                <?php if (!$isReadOnly): ?>
                <td style="text-align:center;">
                    <div class="struct-actions">
                        <button type="button" class="btn btn-ghost btn-sm struct-edit-btn" title="Edit">
                            <?= icon('edit', 12) ?>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm struct-save-btn" title="Save" style="display:none;">
                            <?= icon('check', 12) ?>
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm struct-cancel-btn" title="Cancel" style="display:none;">
                            <?= icon('x', 12) ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm struct-drop-btn" title="Drop column"
                                data-column="<?= h($col['Field']) ?>">
                            <?= icon('trash', 12) ?>
                        </button>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add Column Form (hidden by default) -->
<?php if (!$isReadOnly): ?>
<div id="add-column-form" style="display:none;margin-top:12px;">
    <div class="settings-section">
        <h3 class="section-title"><?= icon('plus', 16) ?> Add New Column</h3>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label">Column Name</label>
                <input type="text" id="new-col-name" class="settings-input" placeholder="column_name">
            </div>
            <div class="settings-field">
                <label class="settings-label">Type</label>
                <input type="text" id="new-col-type" class="settings-input" list="mysql-types" placeholder="VARCHAR(255)">
            </div>
            <div class="settings-field" style="flex:0.5;">
                <label class="settings-label">Null</label>
                <label class="settings-check" style="padding:8px 0;">
                    <input type="checkbox" id="new-col-null" checked> Allow NULL
                </label>
            </div>
        </div>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label">Default Value</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="new-col-default" class="settings-input" placeholder="Default value" style="flex:1;">
                    <label class="struct-check-mini">
                        <input type="checkbox" id="new-col-default-null" checked> NULL
                    </label>
                </div>
            </div>
            <div class="settings-field">
                <label class="settings-label">Comment</label>
                <input type="text" id="new-col-comment" class="settings-input" placeholder="Optional comment">
            </div>
            <div class="settings-field" style="flex:0.6;">
                <label class="settings-label">Position</label>
                <select id="new-col-after" class="settings-input">
                    <option value="">At end</option>
                    <option value="FIRST">First</option>
                    <?php foreach ($columns as $col): ?>
                    <option value="<?= h($col['Field']) ?>">After <?= h($col['Field']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;">
            <button type="button" class="btn btn-primary btn-sm" id="add-column-submit">
                <?= icon('plus', 13) ?> Add Column
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="add-column-cancel">
                <?= icon('x', 13) ?> Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /structure-editor -->

<!-- MySQL Type Datalist -->
<datalist id="mysql-types">
    <option value="INT">
    <option value="INT UNSIGNED">
    <option value="TINYINT">
    <option value="TINYINT(1)">
    <option value="SMALLINT">
    <option value="MEDIUMINT">
    <option value="BIGINT">
    <option value="BIGINT UNSIGNED">
    <option value="FLOAT">
    <option value="DOUBLE">
    <option value="DECIMAL(10,2)">
    <option value="DECIMAL(15,2)">
    <option value="VARCHAR(50)">
    <option value="VARCHAR(100)">
    <option value="VARCHAR(150)">
    <option value="VARCHAR(255)">
    <option value="CHAR(1)">
    <option value="CHAR(36)">
    <option value="TEXT">
    <option value="TINYTEXT">
    <option value="MEDIUMTEXT">
    <option value="LONGTEXT">
    <option value="BLOB">
    <option value="MEDIUMBLOB">
    <option value="LONGBLOB">
    <option value="DATE">
    <option value="DATETIME">
    <option value="DATETIME DEFAULT CURRENT_TIMESTAMP">
    <option value="TIMESTAMP">
    <option value="TIMESTAMP DEFAULT CURRENT_TIMESTAMP">
    <option value="TIME">
    <option value="YEAR">
    <option value="BOOLEAN">
    <option value="ENUM('value1','value2')">
    <option value="SET('value1','value2')">
    <option value="JSON">
    <option value="BINARY(16)">
    <option value="VARBINARY(255)">
</datalist>

<!-- Keys & Relations -->
<?php if (!empty($foreignKeys) || !empty($referencedBy)): ?>
<?php
// Group FKs
$fkGrouped = [];
foreach ($foreignKeys as $fk) {
    $name = $fk['CONSTRAINT_NAME'];
    if (!isset($fkGrouped[$name])) {
        $fkGrouped[$name] = ['name' => $name, 'columns' => [], 'ref_table' => $fk['REFERENCED_TABLE_NAME'],
            'ref_schema' => $fk['REFERENCED_TABLE_SCHEMA'], 'ref_columns' => [],
            'on_update' => $fk['UPDATE_RULE'], 'on_delete' => $fk['DELETE_RULE']];
    }
    $fkGrouped[$name]['columns'][] = $fk['COLUMN_NAME'];
    $fkGrouped[$name]['ref_columns'][] = $fk['REFERENCED_COLUMN_NAME'];
}
// Group reverse refs
$refGrouped = [];
foreach ($referencedBy as $ref) {
    $name = $ref['CONSTRAINT_NAME'];
    if (!isset($refGrouped[$name])) {
        $refGrouped[$name] = ['table' => $ref['referencing_table'], 'columns' => [],
            'local_columns' => [], 'on_update' => $ref['UPDATE_RULE'], 'on_delete' => $ref['DELETE_RULE']];
    }
    $refGrouped[$name]['columns'][] = $ref['referencing_column'];
    $refGrouped[$name]['local_columns'][] = $ref['local_column'];
}
?>

<?php if (!empty($fkGrouped)): ?>
<h3 class="section-title" style="margin-top:20px;"><?= icon('external-link', 16) ?> Foreign Keys</h3>
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Constraint</th>
                <th>Column</th>
                <th></th>
                <th>References</th>
                <th>On Delete</th>
                <th>On Update</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fkGrouped as $fk): ?>
            <tr>
                <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-muted);"><?= h($fk['name']) ?></td>
                <td><code class="rel-col"><?= h(implode(', ', $fk['columns'])) ?></code></td>
                <td class="rel-arrow">→</td>
                <td>
                    <a href="?db=<?= urlencode($fk['ref_schema'] ?? $currentDb) ?>&table=<?= urlencode($fk['ref_table']) ?>&tab=structure" class="rel-table-link">
                        <?= icon('table', 12) ?> <?= h($fk['ref_table']) ?>
                    </a>
                    <code class="rel-col">.<?= h(implode(', .', $fk['ref_columns'])) ?></code>
                </td>
                <td><span class="rel-rule rel-rule-<?= strtolower($fk['on_delete']) ?>"><?= h($fk['on_delete']) ?></span></td>
                <td><span class="rel-rule rel-rule-<?= strtolower($fk['on_update']) ?>"><?= h($fk['on_update']) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($refGrouped)): ?>
<h3 class="section-title" style="margin-top:20px;"><?= icon('layers', 16) ?> Referenced By</h3>
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Constraint</th>
                <th>From Table</th>
                <th></th>
                <th>This Column</th>
                <th>On Delete</th>
                <th>On Update</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($refGrouped as $cname => $ref): ?>
            <tr>
                <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-muted);"><?= h($cname) ?></td>
                <td>
                    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($ref['table']) ?>&tab=structure" class="rel-table-link">
                        <?= icon('table', 12) ?> <?= h($ref['table']) ?>
                    </a>
                    <code class="rel-col">.<?= h(implode(', .', $ref['columns'])) ?></code>
                </td>
                <td class="rel-arrow">→</td>
                <td><code class="rel-col"><?= h(implode(', ', $ref['local_columns'])) ?></code></td>
                <td><span class="rel-rule rel-rule-<?= strtolower($ref['on_delete']) ?>"><?= h($ref['on_delete']) ?></span></td>
                <td><span class="rel-rule rel-rule-<?= strtolower($ref['on_update']) ?>"><?= h($ref['on_update']) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Indexes -->
<div class="table-toolbar">
    <h3 class="section-title" style="margin-bottom:0;">
        <?= icon('key', 16) ?> Indexes
    </h3>
    <?php if (!$isReadOnly): ?>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-primary btn-sm" id="add-index-btn">
            <?= icon('plus', 13) ?> Add Index
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="table-wrapper">
    <table class="data-table" id="indexes-table">
        <thead>
            <tr>
                <th>Key Name</th>
                <th>Type</th>
                <th>Columns</th>
                <th>Cardinality</th>
                <?php if (!$isReadOnly): ?>
                <th style="width:90px;">Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($indexGroups)): ?>
            <tr><td colspan="<?= $isReadOnly ? 4 : 5 ?>" style="text-align:center;color:var(--text-muted);padding:20px;">No indexes</td></tr>
            <?php else: ?>
            <?php foreach ($indexGroups as $g): ?>
            <tr data-index-name="<?= h($g['Key_name']) ?>">
                <td style="color:var(--gold);font-weight:600;">
                    <?php if ($g['kind'] === 'PRIMARY'): ?><?= icon('key', 11) ?> <?php endif; ?><?= h($g['Key_name']) ?>
                </td>
                <td>
                    <?php
                    $kindClass = match($g['kind']) {
                        'PRIMARY'  => 'badge-primary',
                        'UNIQUE'   => 'badge-unique',
                        'FULLTEXT' => 'badge-index',
                        'SPATIAL'  => 'badge-index',
                        default    => 'badge-index',
                    };
                    ?>
                    <span class="badge <?= $kindClass ?>"><?= h($g['kind']) ?></span>
                    <?php if ($g['Index_type'] !== 'BTREE' && $g['kind'] === 'INDEX'): ?>
                    <span style="color:var(--text-muted);font-size:var(--font-size-xs);margin-left:6px;"><?= h($g['Index_type']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);">
                    <?php
                    $colLabels = [];
                    foreach ($g['columns'] as $c) {
                        $lbl = h($c['Column_name']);
                        if (!empty($c['Sub_part'])) $lbl .= '(' . (int)$c['Sub_part'] . ')';
                        $colLabels[] = $lbl;
                    }
                    echo implode(', ', $colLabels);
                    ?>
                </td>
                <td class="cell-number"><?= h($g['Cardinality'] ?? '—') ?></td>
                <?php if (!$isReadOnly): ?>
                <td>
                    <button type="button" class="btn btn-ghost btn-sm index-drop-btn" data-index="<?= h($g['Key_name']) ?>" style="color:var(--danger);">
                        <?= icon('trash', 11) ?> Drop
                    </button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!$isReadOnly): ?>
<!-- Add index form (hidden by default) -->
<div id="add-index-form" style="display:none;margin-top:12px;">
    <div class="settings-section">
        <h3 class="section-title"><?= icon('plus', 16) ?> Add Index</h3>
        <div class="settings-grid">
            <div class="settings-field">
                <label class="settings-label">Type</label>
                <select id="add-index-type" class="settings-input">
                    <option value="INDEX">INDEX</option>
                    <option value="UNIQUE">UNIQUE</option>
                    <option value="PRIMARY">PRIMARY</option>
                    <option value="FULLTEXT">FULLTEXT</option>
                    <option value="SPATIAL">SPATIAL</option>
                </select>
            </div>
            <div class="settings-field" id="add-index-name-field">
                <label class="settings-label">Name <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <input type="text" id="add-index-name" class="settings-input" placeholder="idx_name (auto if blank)" autocomplete="off">
            </div>
        </div>
        <div class="settings-field" style="margin-top:12px;">
            <label class="settings-label">Columns <span style="color:var(--text-muted);font-weight:400;">(click to select in order; click again to deselect)</span></label>
            <div id="add-index-columns" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                <?php foreach ($columns as $col): ?>
                <button type="button" class="btn btn-ghost btn-sm index-col-chip" data-col="<?= h($col['Field']) ?>" style="font-family:var(--font-mono);">
                    <?= h($col['Field']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div id="add-index-selected" style="margin-top:8px;min-height:24px;font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);">
                <span style="color:var(--text-muted);">No columns selected.</span>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;">
            <button type="button" class="btn btn-primary btn-sm" id="add-index-submit">
                <?= icon('check', 13) ?> Add Index
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="add-index-cancel">
                <?= icon('x', 13) ?> Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Statement -->
<h3 class="section-title" style="margin-top:24px;"><?= icon('code', 16) ?> Create Statement</h3>
<div class="code-block" id="create-statement-block"><code id="create-statement-code"><?= h($createSql) ?></code></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('create-statement-code');
    if (!el || typeof Ledger === 'undefined') return;
    var raw = el.textContent;
    var tokens = Ledger.tokenize(raw);
    tokens = Ledger.resolveTableNames(tokens);
    el.innerHTML = Ledger.renderTokens(tokens);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editor = document.getElementById('structure-editor');
    if (!editor || editor.dataset.readonly === '1') return;

    const db = editor.dataset.db;
    const table = editor.dataset.table;
    const csrf = editor.dataset.csrf;

    // Edit / Save / Cancel per row

    editor.querySelectorAll('.struct-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            row.classList.add('struct-editing');
            row.querySelectorAll('.struct-display').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.struct-input, .struct-check, .struct-default-wrap, .struct-extra-select').forEach(el => el.style.display = '');
            row.querySelector('.struct-edit-btn').style.display = 'none';
            row.querySelector('.struct-save-btn').style.display = '';
            row.querySelector('.struct-cancel-btn').style.display = '';
            // Focus name input
            const nameInput = row.querySelector('[data-field="name"] .struct-input');
            if (nameInput) nameInput.focus();
        });
    });

    editor.querySelectorAll('.struct-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            window.location.reload();
        });
    });

    editor.querySelectorAll('.struct-save-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            saveColumn(row);
        });
    });

    // Default NULL checkbox toggles default input
    editor.querySelectorAll('.struct-default-null').forEach(cb => {
        cb.addEventListener('change', () => {
            const input = cb.closest('.struct-default-wrap').querySelector('.struct-default-input');
            input.disabled = cb.checked;
            if (cb.checked) input.value = '';
        });
    });

    function saveColumn(row) {
        const origName = row.dataset.originalName;
        const newName = row.querySelector('[data-field="name"] .struct-input').value.trim();
        const newType = row.querySelector('[data-field="type"] .struct-input').value.trim();
        const nullable = row.querySelector('.struct-null-check').checked;
        const defaultNull = row.querySelector('.struct-default-null').checked;
        const defaultVal = defaultNull ? null : row.querySelector('.struct-default-input').value;
        const extra = row.querySelector('.struct-extra-select').value;
        const comment = row.querySelector('.struct-comment-input').value;

        if (!newName || !newType) {
            Ledger.setStatus('Column name and type are required.');
            return;
        }

        row.style.opacity = '0.5';

        const formData = new FormData();
        formData.append('action', 'alter_column');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('original_name', origName);
        formData.append('new_name', newName);
        formData.append('new_type', newType);
        formData.append('nullable', nullable ? '1' : '0');
        formData.append('default_value', defaultVal ?? '');
        formData.append('default_null', defaultNull ? '1' : '0');
        formData.append('extra', extra);
        formData.append('comment', comment);
        formData.append('_csrf_token', csrf);

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                row.style.opacity = '1';
                if (data.error) {
                    Ledger.setStatus('Error: ' + data.error);
                    return;
                }
                Ledger.setStatus('Column "' + newName + '" updated successfully.');
                window.location.reload();
            })
            .catch(err => {
                row.style.opacity = '1';
                Ledger.setStatus('Network error: ' + err.message);
            });
    }

    // Drop Column

    editor.querySelectorAll('.struct-drop-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const colName = btn.dataset.column;
            Ledger.confirm({
                title: 'Drop Column',
                message: 'Drop column "' + colName + '" from ' + table + '? This will delete all data in this column permanently.',
                confirmText: 'Drop Column',
                cancelText: 'Cancel',
                danger: true,
            }).then(ok => {
                if (!ok) return;
                const formData = new FormData();
                formData.append('action', 'drop_column');
                formData.append('db', db);
                formData.append('table', table);
                formData.append('column', colName);
                formData.append('_csrf_token', csrf);

                fetch('ajax.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            Ledger.setStatus('Error: ' + data.error);
                            return;
                        }
                        Ledger.setStatus('Column "' + colName + '" dropped.');
                        window.location.reload();
                    });
            });
        });
    });

    // Add Column

    const addBtn = document.getElementById('add-column-btn');
    const addForm = document.getElementById('add-column-form');
    const addCancel = document.getElementById('add-column-cancel');
    const addSubmit = document.getElementById('add-column-submit');

    if (addBtn && addForm) {
        addBtn.addEventListener('click', () => {
            addForm.style.display = '';
            addBtn.style.display = 'none';
            document.getElementById('new-col-name').focus();
        });

        addCancel.addEventListener('click', () => {
            addForm.style.display = 'none';
            addBtn.style.display = '';
        });

        // Default NULL toggle for add form
        document.getElementById('new-col-default-null').addEventListener('change', function() {
            document.getElementById('new-col-default').disabled = this.checked;
            if (this.checked) document.getElementById('new-col-default').value = '';
        });

        addSubmit.addEventListener('click', () => {
            const name = document.getElementById('new-col-name').value.trim();
            const type = document.getElementById('new-col-type').value.trim();
            const nullable = document.getElementById('new-col-null').checked;
            const defaultNull = document.getElementById('new-col-default-null').checked;
            const defaultVal = defaultNull ? null : document.getElementById('new-col-default').value;
            const comment = document.getElementById('new-col-comment').value;
            const after = document.getElementById('new-col-after').value;

            if (!name || !type) {
                Ledger.setStatus('Column name and type are required.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_column');
            formData.append('db', db);
            formData.append('table', table);
            formData.append('name', name);
            formData.append('type', type);
            formData.append('nullable', nullable ? '1' : '0');
            formData.append('default_value', defaultVal ?? '');
            formData.append('default_null', defaultNull ? '1' : '0');
            formData.append('comment', comment);
            formData.append('after', after);
            formData.append('_csrf_token', csrf);

            fetch('ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        Ledger.setStatus('Error: ' + data.error);
                        return;
                    }
                    Ledger.setStatus('Column "' + name + '" added.');
                    window.location.reload();
                })
                .catch(err => {
                    Ledger.setStatus('Network error: ' + err.message);
                });
        });
    }

    // Keyboard: Enter saves, Escape cancels
    editor.addEventListener('keydown', function(e) {
        const row = e.target.closest('.struct-editing');
        if (!row) return;
        if (e.key === 'Enter') { e.preventDefault(); saveColumn(row); }
        if (e.key === 'Escape') { e.preventDefault(); window.location.reload(); }
    });

    // Auto Increment

    var setBtn = document.getElementById('auto-inc-set-btn');
    var resetBtn = document.getElementById('auto-inc-reset-btn');
    var incInput = document.getElementById('auto-inc-input');

    function setAutoInc(value) {
        var formData = new FormData();
        formData.append('action', 'set_auto_increment');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('value', value);
        formData.append('_csrf_token', csrf);

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    Ledger.setStatus('Error: ' + data.error);
                    return;
                }
                Ledger.setStatus('AUTO_INCREMENT set to ' + data.new_value);
                window.location.reload();
            })
            .catch(function(err) {
                Ledger.setStatus('Network error: ' + err.message);
            });
    }

    if (setBtn) {
        setBtn.addEventListener('click', function() {
            var val = parseInt(incInput.value);
            if (isNaN(val) || val < 1) {
                Ledger.setStatus('AUTO_INCREMENT must be a positive number.');
                return;
            }
            Ledger.confirm({
                title: 'Set AUTO_INCREMENT',
                message: 'Set AUTO_INCREMENT to ' + val + '? This will affect the next inserted row\'s ID.',
                confirmText: 'Set to ' + val,
                cancelText: 'Cancel',
                danger: false,
            }).then(function(ok) {
                if (ok) setAutoInc(val);
            });
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            Ledger.confirm({
                title: 'Reset AUTO_INCREMENT',
                message: 'Reset to MAX(' + '<?= h($autoIncCol ?? "id") ?>' + ') + 1? This closes any gaps in the ID sequence.',
                confirmText: 'Reset',
                cancelText: 'Cancel',
                danger: false,
            }).then(function(ok) {
                if (ok) setAutoInc(0); // 0 = server calculates reset
            });
        });
    }

    // ─── Index management ──────────────────────────────────────
    const idxAddBtn     = document.getElementById('add-index-btn');
    const idxAddForm    = document.getElementById('add-index-form');
    const idxAddCancel  = document.getElementById('add-index-cancel');
    const idxAddSubmit  = document.getElementById('add-index-submit');
    const idxTypeSelect = document.getElementById('add-index-type');
    const idxNameField  = document.getElementById('add-index-name-field');
    const idxSelectedEl = document.getElementById('add-index-selected');

    let idxSelectedCols = []; // ordered list of column names

    function renderSelected() {
        if (idxSelectedCols.length === 0) {
            idxSelectedEl.innerHTML = '<span style="color:var(--text-muted);">No columns selected.</span>';
        } else {
            idxSelectedEl.textContent = '→ ' + idxSelectedCols.join(', ');
        }
        // Update chip order numbers
        document.querySelectorAll('.index-col-chip').forEach(c => {
            const col = c.dataset.col;
            const pos = idxSelectedCols.indexOf(col);
            if (pos >= 0) {
                c.classList.add('active');
                c.setAttribute('data-order', String(pos + 1));
            } else {
                c.classList.remove('active');
                c.removeAttribute('data-order');
            }
        });
    }

    document.querySelectorAll('.index-col-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const col = chip.dataset.col;
            const pos = idxSelectedCols.indexOf(col);
            if (pos >= 0) {
                idxSelectedCols.splice(pos, 1);
            } else {
                idxSelectedCols.push(col);
            }
            renderSelected();
        });
    });

    if (idxTypeSelect) {
        // PRIMARY has no user-provided name
        idxTypeSelect.addEventListener('change', () => {
            idxNameField.style.display = idxTypeSelect.value === 'PRIMARY' ? 'none' : '';
        });
    }

    if (idxAddBtn && idxAddForm) {
        idxAddBtn.addEventListener('click', () => {
            idxAddForm.style.display = '';
            idxAddBtn.style.display = 'none';
            document.getElementById('add-index-name').focus();
        });
        idxAddCancel.addEventListener('click', () => {
            idxAddForm.style.display = 'none';
            idxAddBtn.style.display = '';
            idxSelectedCols = [];
            document.getElementById('add-index-name').value = '';
            renderSelected();
        });
    }

    if (idxAddSubmit) {
        idxAddSubmit.addEventListener('click', () => {
            const type = idxTypeSelect.value;
            const name = document.getElementById('add-index-name').value.trim();
            if (idxSelectedCols.length === 0) {
                Ledger.alert({ title: 'No columns', message: 'Select at least one column for the index.' });
                return;
            }
            if (type === 'FULLTEXT' && idxSelectedCols.length === 0) {
                Ledger.alert({ title: 'Invalid', message: 'FULLTEXT indexes need at least one TEXT/VARCHAR column.' });
                return;
            }
            const fd = new FormData();
            fd.append('action', 'add_index');
            fd.append('db', db);
            fd.append('table', table);
            fd.append('type', type);
            fd.append('name', name);
            fd.append('columns', idxSelectedCols.join(','));
            fd.append('_csrf_token', csrf);
            idxAddSubmit.disabled = true;
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    idxAddSubmit.disabled = false;
                    if (data.error) {
                        Ledger.alert({ title: 'Add index failed', message: data.error });
                        return;
                    }
                    location.reload();
                })
                .catch(() => {
                    idxAddSubmit.disabled = false;
                    Ledger.alert({ title: 'Add index failed', message: 'Network error.' });
                });
        });
    }

    document.querySelectorAll('.index-drop-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const indexName = btn.dataset.index;
            const isPrimary = indexName === 'PRIMARY';
            Ledger.confirm({
                title: isPrimary ? 'Drop PRIMARY KEY' : 'Drop index',
                message: isPrimary
                    ? 'Drop the PRIMARY KEY on "' + table + '"? Rows will lose their primary identifier. This cannot be undone.'
                    : 'Drop index "' + indexName + '" from "' + table + '"? This cannot be undone.',
                confirmText: 'Drop',
                cancelText: 'Cancel',
                danger: true,
            }).then(ok => {
                if (!ok) return;
                const fd = new FormData();
                fd.append('action', 'drop_index');
                fd.append('db', db);
                fd.append('table', table);
                fd.append('index', indexName);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            Ledger.alert({ title: 'Drop failed', message: data.error });
                            return;
                        }
                        location.reload();
                    })
                    .catch(() => {
                        Ledger.alert({ title: 'Drop failed', message: 'Network error.' });
                    });
            });
        });
    });
});
</script>

<?php
// Partitions & Information panels
$tableStatus = null;
$partitions = [];
$panelError = null;
try {
    $tableStatus = $dbInstance->getTableStatus($currentDb, $currentTable);
    $partitions = $dbInstance->getPartitions($currentDb, $currentTable);
} catch (Exception $e) {
    $panelError = $e->getMessage();
}
?>

<?php if ($panelError): ?>
<div class="error-box" style="margin-top:24px;">
    <strong>Could not load table info:</strong> <?= h($panelError) ?>
</div>
<?php endif; ?>

<?php if ($tableStatus): ?>

<!-- Information Panels -->
<?php
$dataLen   = (int)($tableStatus['Data_length'] ?? 0);
$indexLen  = (int)($tableStatus['Index_length'] ?? 0);
$overhead  = (int)($tableStatus['Data_free'] ?? 0);
$effective = $dataLen + $indexLen;
$total     = $effective + $overhead;

$fmtDate = function ($d) {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('M d, Y \a\t h:i A', $t) : $d;
};
?>

<div class="info-grid-pair" style="margin-top:16px;">
    <div class="panel-section">
        <div class="panel-section-header">
            <?= icon('database', 14) ?> Space Usage
        </div>
        <div class="panel-section-body">
            <table class="info-kv-table">
                <tr><td>Data</td><td class="cell-number"><?= format_bytes($dataLen) ?></td></tr>
                <tr><td>Index</td><td class="cell-number"><?= format_bytes($indexLen) ?></td></tr>
                <tr>
                    <td>Overhead</td>
                    <td class="cell-number" style="<?= $overhead > 0 ? 'color:var(--warning);' : '' ?>">
                        <?= format_bytes($overhead) ?>
                    </td>
                </tr>
                <tr><td><strong>Effective</strong></td><td class="cell-number"><strong><?= format_bytes($effective) ?></strong></td></tr>
                <tr><td><strong>Total</strong></td><td class="cell-number"><strong><?= format_bytes($total) ?></strong></td></tr>
            </table>
        </div>
    </div>

    <div class="panel-section">
        <div class="panel-section-header">
            <?= icon('info', 14) ?> Row Statistics
        </div>
        <div class="panel-section-body">
            <table class="info-kv-table">
                <tr><td>Format</td><td><?= h(strtolower($tableStatus['Row_format'] ?? '—')) ?></td></tr>
                <tr><td>Collation</td><td style="font-family:var(--font-mono);font-size:var(--font-size-xs);"><?= h($tableStatus['Collation'] ?? '—') ?></td></tr>
                <tr><td>Engine</td><td style="color:var(--purple);font-family:var(--font-mono);"><?= h($tableStatus['Engine'] ?? '—') ?></td></tr>
                <tr><td>Next autoindex</td><td class="cell-number"><?= $tableStatus['Auto_increment'] !== null ? format_number((int)$tableStatus['Auto_increment']) : '—' ?></td></tr>
                <tr><td>Creation</td><td style="font-size:var(--font-size-xs);"><?= h($fmtDate($tableStatus['Create_time'] ?? null)) ?></td></tr>
                <tr><td>Last update</td><td style="font-size:var(--font-size-xs);"><?= h($fmtDate($tableStatus['Update_time'] ?? null)) ?></td></tr>
                <tr><td>Last check</td><td style="font-size:var(--font-size-xs);"><?= h($fmtDate($tableStatus['Check_time'] ?? null)) ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- ── Maintenance Panel ── -->
<?php if (!(isset($auth) && $auth->isReadOnly())): ?>
<div class="panel-section" style="margin-top:16px;">
    <div class="panel-section-header"><?= icon('settings', 14) ?> Maintenance</div>
    <div class="panel-section-body" style="padding:0;">
        <table class="info-kv-table" style="margin:0;">
            <tr>
                <td style="padding:12px 16px;"><strong>Optimize table</strong><div style="font-size:var(--font-size-xs);color:var(--text-muted);margin-top:2px;">Rebuild the table to reclaim unused space and defragment data and index pages.</div></td>
                <td style="width:80px;text-align:right;padding-right:16px;"><button type="button" class="btn btn-ghost btn-sm maint-btn" data-op="OPTIMIZE">Run</button></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;"><strong>Analyze table</strong><div style="font-size:var(--font-size-xs);color:var(--text-muted);margin-top:2px;">Update key distribution statistics so the optimizer makes better query plans.</div></td>
                <td style="width:80px;text-align:right;padding-right:16px;"><button type="button" class="btn btn-ghost btn-sm maint-btn" data-op="ANALYZE">Run</button></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;"><strong>Check table</strong><div style="font-size:var(--font-size-xs);color:var(--text-muted);margin-top:2px;">Look for errors. Read-only — does not modify the table.</div></td>
                <td style="width:80px;text-align:right;padding-right:16px;"><button type="button" class="btn btn-ghost btn-sm maint-btn" data-op="CHECK">Run</button></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;"><strong>Repair table</strong><div style="font-size:var(--font-size-xs);color:var(--text-muted);margin-top:2px;">Attempt to repair a corrupted table. MyISAM/ARIA only — InnoDB has no equivalent.</div></td>
                <td style="width:80px;text-align:right;padding-right:16px;"><button type="button" class="btn btn-ghost btn-sm maint-btn" data-op="REPAIR">Run</button></td>
            </tr>
        </table>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';
    document.querySelectorAll('.maint-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var op = btn.dataset.op;
            var db = <?= json_encode($currentDb) ?>;
            var table = <?= json_encode($currentTable) ?>;
            btn.disabled = true;
            var orig = btn.textContent;
            btn.textContent = 'Running…';
            var fd = new FormData();
            fd.append('action', 'maintenance');
            fd.append('operation', op);
            fd.append('db', db);
            fd.append('table', table);
            fd.append('_csrf_token', csrfToken);
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data.error) {
                        btn.textContent = 'Error'; btn.style.color = 'var(--danger)';
                        if (typeof Ledger !== 'undefined') Ledger.setStatus('Error: ' + data.error);
                        else alert('Error: ' + data.error);
                    } else {
                        btn.textContent = '✓ Done'; btn.style.color = 'var(--accent)';
                        var msg = op + ' TABLE completed.';
                        if (data.result && data.result.length) msg += ' Status: ' + (data.result[0].Msg_text || 'OK');
                        if (typeof Ledger !== 'undefined') Ledger.setStatus(msg);
                    }
                    setTimeout(function() { btn.textContent = orig; btn.style.color = ''; }, 3000);
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Error';
                    btn.style.color = 'var(--danger)';
                    setTimeout(function() { btn.textContent = orig; btn.style.color = ''; }, 3000);
                });
        });
    });
});
</script>
<?php endif; ?>

<!-- ── Partitions Panel ── -->
<?php
$partitions = [];
$partInfo = null;
try {
    $partitions = $dbInstance->getPartitions($currentDb, $currentTable);
    $partInfo = $dbInstance->getPartitionInfo($currentDb, $currentTable);
} catch (Exception $e) {}
$isPartitioned = !empty($partitions);
$isReadOnlyMode = isset($auth) && $auth->isReadOnly();
?>
<div class="panel-section" style="margin-top:16px;">
    <div class="panel-section-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:8px;"><?= icon('layers', 14) ?> Partitions</span>
        <div style="display:flex;align-items:center;gap:6px;">
            <?php if ($isPartitioned): ?>
            <span style="font-size:var(--font-size-xs);color:var(--text-muted);">
                <?= strtoupper($partInfo['PARTITION_METHOD'] ?? '') ?> on <?= h($partInfo['PARTITION_EXPRESSION'] ?? '') ?>
                · <?= count($partitions) ?> partition<?= count($partitions) !== 1 ? 's' : '' ?>
            </span>
            <?php if (!$isReadOnlyMode): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="part-add-btn"><?= icon('plus', 11) ?> Add</button>
            <button type="button" class="btn btn-danger btn-sm" id="part-remove-btn">Remove All</button>
            <?php endif; ?>
            <?php else: ?>
            <span style="font-size:var(--font-size-xs);color:var(--text-muted);">Not partitioned</span>
            <?php if (!$isReadOnlyMode): ?>
            <button type="button" class="btn btn-primary btn-sm" id="part-create-btn"><?= icon('plus', 11) ?> Create Partitioning</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div style="padding:0;">
        <?php if (!$isPartitioned): ?>
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:var(--font-size-sm);">
            This table is not partitioned.
        </div>
        <?php else: ?>
        <table class="data-table" style="margin:0;font-size:var(--font-size-sm);">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th style="text-align:right;">Rows</th>
                    <th style="text-align:right;">Data</th>
                    <th style="text-align:right;">Index</th>
                    <?php if (!$isReadOnlyMode): ?>
                    <th style="width:140px;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($partitions as $p):
                $dataLen = (int)($p['DATA_LENGTH'] ?? 0);
                $indexLen = (int)($p['INDEX_LENGTH'] ?? 0);
            ?>
                <tr>
                    <td style="color:var(--text-muted);"><?= h($p['PARTITION_ORDINAL_POSITION']) ?></td>
                    <td style="font-family:var(--font-mono);font-weight:600;"><?= h($p['PARTITION_NAME']) ?></td>
                    <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);">
                        <?= h($p['PARTITION_DESCRIPTION'] ?: '—') ?>
                    </td>
                    <td style="text-align:right;font-family:var(--font-mono);"><?= number_format((int)($p['TABLE_ROWS'] ?? 0)) ?></td>
                    <td style="text-align:right;font-size:var(--font-size-xs);color:var(--text-muted);"><?= format_bytes($dataLen) ?></td>
                    <td style="text-align:right;font-size:var(--font-size-xs);color:var(--text-muted);"><?= format_bytes($indexLen) ?></td>
                    <?php if (!$isReadOnlyMode): ?>
                    <td>
                        <div style="display:flex;gap:3px;">
                            <button type="button" class="btn btn-ghost btn-sm part-action" data-op="optimize" data-part="<?= h($p['PARTITION_NAME']) ?>" title="Optimize"><?= icon('zap', 10) ?></button>
                            <button type="button" class="btn btn-ghost btn-sm part-action" data-op="rebuild" data-part="<?= h($p['PARTITION_NAME']) ?>" title="Rebuild"><?= icon('tool', 10) ?></button>
                            <button type="button" class="btn btn-ghost btn-sm part-action" data-op="truncate" data-part="<?= h($p['PARTITION_NAME']) ?>" title="Truncate"><?= icon('trash', 10) ?></button>
                            <button type="button" class="btn btn-danger btn-sm part-action" data-op="drop" data-part="<?= h($p['PARTITION_NAME']) ?>" title="Drop"><?= icon('x', 10) ?></button>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = <?= json_encode($currentDb) ?>;
    var table = <?= json_encode($currentTable) ?>;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    // Per-partition actions (optimize, rebuild, truncate, drop)
    document.querySelectorAll('.part-action').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var op = btn.dataset.op;
            var part = btn.dataset.part;
            var isDanger = (op === 'drop' || op === 'truncate');

            function doAction() {
                btn.disabled = true;
                var orig = btn.innerHTML;
                btn.textContent = '…';
                var fd = new FormData();
                fd.append('action', 'partition_action');
                fd.append('db', db);
                fd.append('table', table);
                fd.append('op', op);
                fd.append('partition', part);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                        if (data.error) {
                            if (typeof Ledger !== 'undefined') Ledger.setStatus('Error: ' + data.error);
                        } else {
                            if (typeof Ledger !== 'undefined') Ledger.setStatus(op.toUpperCase() + ' PARTITION ' + part + ' completed.');
                            if (isDanger) window.location.reload();
                        }
                    });
            }

            if (isDanger && typeof Ledger !== 'undefined') {
                Ledger.confirm({
                    title: op.charAt(0).toUpperCase() + op.slice(1) + ' Partition',
                    message: op === 'drop'
                        ? 'Drop partition "' + part + '"? All data in this partition will be permanently deleted.'
                        : 'Truncate partition "' + part + '"? All rows in this partition will be deleted but the partition itself remains.',
                    confirmText: op.charAt(0).toUpperCase() + op.slice(1),
                    danger: true,
                }).then(function(ok) { if (ok) doAction(); });
            } else {
                doAction();
            }
        });
    });

    // Create partitioning
    var createBtn = document.getElementById('part-create-btn');
    if (createBtn) {
        createBtn.addEventListener('click', function() {
            var old = document.getElementById('ledger-modal');
            if (old) old.remove();

            var overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'ledger-modal';
            overlay.innerHTML =
                '<div class="modal-box" style="max-width:650px;">' +
                    '<div class="modal-header"><span class="modal-title">Create Partitioning</span><button class="modal-close" data-action="cancel">&times;</button></div>' +
                    '<div class="modal-body">' +
                        '<div id="part-err" class="error-box" style="display:none;margin-bottom:12px;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
                            '<div><label class="settings-label">Method</label>' +
                                '<select id="part-method" class="settings-input" style="width:100%;">' +
                                    '<option value="RANGE">RANGE</option>' +
                                    '<option value="LIST">LIST</option>' +
                                    '<option value="HASH">HASH</option>' +
                                    '<option value="KEY">KEY</option>' +
                                    '<option value="RANGE COLUMNS">RANGE COLUMNS</option>' +
                                    '<option value="LIST COLUMNS">LIST COLUMNS</option>' +
                                '</select></div>' +
                            '<div><label class="settings-label">Expression / Column</label>' +
                                '<input type="text" id="part-expr" class="settings-input" placeholder="e.g. YEAR(created_at) or id" style="width:100%;"></div>' +
                        '</div>' +
                        '<div style="margin-bottom:12px;">' +
                            '<label class="settings-label">Number of partitions <span style="color:var(--text-muted);font-weight:400;">(for HASH/KEY)</span></label>' +
                            '<input type="number" id="part-count" class="settings-input" value="4" min="1" max="1024" style="width:120px;">' +
                        '</div>' +
                        '<div>' +
                            '<label class="settings-label">Partition definitions <span style="color:var(--text-muted);font-weight:400;">(for RANGE/LIST)</span></label>' +
                            '<textarea id="part-defs" class="settings-textarea" rows="6" style="font-family:var(--font-mono);font-size:var(--font-size-sm);width:100%;" placeholder="PARTITION p0 VALUES LESS THAN (2020),\nPARTITION p1 VALUES LESS THAN (2021),\nPARTITION p2 VALUES LESS THAN (2022),\nPARTITION pmax VALUES LESS THAN MAXVALUE"></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-footer"><button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button><button class="btn btn-primary modal-btn" id="part-save">Create</button></div>' +
                '</div>';
            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

            document.getElementById('part-save').addEventListener('click', function() {
                var method = document.getElementById('part-method').value;
                var expr = document.getElementById('part-expr').value.trim();
                var count = document.getElementById('part-count').value;
                var defs = document.getElementById('part-defs').value.trim();
                var errEl = document.getElementById('part-err');
                errEl.style.display = 'none';

                if (!expr) { errEl.textContent = 'Expression or column is required.'; errEl.style.display = ''; return; }

                var sql;
                if (method === 'HASH' || method === 'KEY') {
                    sql = 'PARTITION BY ' + method + ' (' + expr + ') PARTITIONS ' + count;
                } else {
                    if (!defs) { errEl.textContent = 'Partition definitions are required for ' + method + '.'; errEl.style.display = ''; return; }
                    sql = 'PARTITION BY ' + method + ' (' + expr + ') (\n' + defs + '\n)';
                }

                var fd = new FormData();
                fd.append('action', 'partition_table');
                fd.append('db', db);
                fd.append('table', table);
                fd.append('sql', sql);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { errEl.textContent = data.error; errEl.style.display = ''; return; }
                        closeModal();
                        window.location.reload();
                    });
            });

            function closeModal() { overlay.classList.remove('modal-visible'); setTimeout(function() { overlay.remove(); }, 150); }
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay || (e.target.dataset && e.target.dataset.action === 'cancel') || (e.target.closest && e.target.closest('[data-action="cancel"]'))) closeModal();
            });
        });
    }

    // Add partition
    var addBtn = document.getElementById('part-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            var old = document.getElementById('ledger-modal');
            if (old) old.remove();

            var overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'ledger-modal';
            overlay.innerHTML =
                '<div class="modal-box" style="max-width:500px;">' +
                    '<div class="modal-header"><span class="modal-title">Add Partition</span><button class="modal-close" data-action="cancel">&times;</button></div>' +
                    '<div class="modal-body">' +
                        '<div id="part-add-err" class="error-box" style="display:none;margin-bottom:12px;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                        '<label class="settings-label">Partition definition</label>' +
                        '<textarea id="part-add-def" class="settings-textarea" rows="3" style="font-family:var(--font-mono);font-size:var(--font-size-sm);width:100%;" placeholder="PARTITION p_new VALUES LESS THAN (2025)"></textarea>' +
                    '</div>' +
                    '<div class="modal-footer"><button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button><button class="btn btn-primary modal-btn" id="part-add-save">Add</button></div>' +
                '</div>';
            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

            document.getElementById('part-add-save').addEventListener('click', function() {
                var def = document.getElementById('part-add-def').value.trim();
                var errEl = document.getElementById('part-add-err');
                if (!def) { errEl.textContent = 'Definition is required.'; errEl.style.display = ''; return; }
                var fd = new FormData();
                fd.append('action', 'partition_action');
                fd.append('db', db);
                fd.append('table', table);
                fd.append('op', 'add');
                fd.append('definition', def);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { errEl.textContent = data.error; errEl.style.display = ''; return; }
                        closeModal();
                        window.location.reload();
                    });
            });

            function closeModal() { overlay.classList.remove('modal-visible'); setTimeout(function() { overlay.remove(); }, 150); }
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay || (e.target.dataset && e.target.dataset.action === 'cancel') || (e.target.closest && e.target.closest('[data-action="cancel"]'))) closeModal();
            });
        });
    }

    // Remove all partitioning
    var removeBtn = document.getElementById('part-remove-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            Ledger.confirm({
                title: 'Remove Partitioning',
                message: 'Remove all partitioning from this table? Data will be preserved but merged into a single unpartitioned table.',
                confirmText: 'Remove',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'remove_partitioning');
                fd.append('db', db);
                fd.append('table', table);
                fd.append('_csrf_token', csrf);
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) { Ledger.setStatus('Error: ' + data.error); return; }
                        Ledger.setStatus('Partitioning removed.');
                        window.location.reload();
                    });
            });
        });
    }
});
</script>

<!-- ── Triggers Panel ── -->
<?php
$triggers = [];
$triggersError = null;
try {
    $triggers = $dbInstance->getTriggers($currentDb, $currentTable);
} catch (Exception $e) {
    $triggersError = $e->getMessage();
}
?>

<div class="panel-section" style="margin-top:16px;" id="triggers-panel" data-db="<?= h($currentDb) ?>" data-table="<?= h($currentTable) ?>">
    <div class="panel-section-header" style="justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:8px;"><?= icon('zap', 14) ?> Triggers</span>
        <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
        <button type="button" class="btn btn-ghost btn-sm" id="trigger-add-btn" style="padding:2px 8px;font-size:var(--font-size-xs);">
            <?= icon('plus', 12) ?> Add trigger
        </button>
        <?php endif; ?>
    </div>
    <div class="panel-section-body" style="padding:0;">
        <?php if ($triggersError): ?>
        <div class="panel-empty" style="margin:14px 16px;">
            <?= icon('alert-triangle', 14) ?>
            <span>Could not load triggers: <?= h($triggersError) ?></span>
        </div>
        <?php elseif (empty($triggers)): ?>
        <div class="panel-empty" style="margin:14px 16px;">
            <?= icon('info', 14) ?>
            <span>No triggers defined on this table.</span>
        </div>
        <?php else: ?>
        <div class="trigger-list">
            <?php foreach ($triggers as $t): ?>
            <div class="trigger-item" data-name="<?= h($t['name']) ?>" data-timing="<?= h($t['timing']) ?>" data-event="<?= h($t['event']) ?>" data-body="<?= h($t['body']) ?>">
                <div class="trigger-item-head">
                    <span class="trigger-badge trigger-timing-<?= strtolower($t['timing']) ?>"><?= h($t['timing']) ?></span>
                    <span class="trigger-badge trigger-event-<?= strtolower($t['event']) ?>"><?= h($t['event']) ?></span>
                    <span class="trigger-name"><?= h($t['name']) ?></span>
                    <span class="trigger-definer"><?= h($t['definer']) ?></span>
                    <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
                    <div class="trigger-actions">
                        <button type="button" class="btn btn-ghost btn-sm trigger-edit-btn" title="Edit"><?= icon('edit', 12) ?></button>
                        <button type="button" class="btn btn-danger btn-sm trigger-drop-btn" title="Drop" style="padding:2px 6px;"><?= icon('trash', 12) ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <pre class="trigger-body"><?= h($t['body']) ?></pre>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var panel = document.getElementById('triggers-panel');
    if (!panel) return;
    var db = panel.dataset.db;
    var table = panel.dataset.table;

    function openTriggerModal(mode, data) {
        // mode: 'create' or 'edit'
        if (typeof Ledger !== 'undefined' && Ledger.closeModal) Ledger.closeModal(); else { var old = document.getElementById('ledger-modal'); if (old) old.remove(); }
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';
        overlay.innerHTML =
            '<div class="modal-box" style="max-width:640px;">' +
                '<div class="modal-header">' +
                    '<span class="modal-title">' + (mode === 'create' ? 'Create trigger' : 'Edit trigger') + '</span>' +
                    '<button class="modal-close" data-action="cancel">&times;</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<div class="settings-field">' +
                        '<label class="settings-label">Name</label>' +
                        '<input type="text" id="trg-name" class="settings-input" placeholder="e.g. tbl_after_insert" style="font-family:var(--font-mono);">' +
                    '</div>' +
                    '<div class="ops-grid-2" style="margin-top:10px;">' +
                        '<div class="settings-field">' +
                            '<label class="settings-label">Timing</label>' +
                            '<select id="trg-timing" class="settings-input">' +
                                '<option value="BEFORE">BEFORE</option>' +
                                '<option value="AFTER">AFTER</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="settings-field">' +
                            '<label class="settings-label">Event</label>' +
                            '<select id="trg-event" class="settings-input">' +
                                '<option value="INSERT">INSERT</option>' +
                                '<option value="UPDATE">UPDATE</option>' +
                                '<option value="DELETE">DELETE</option>' +
                            '</select>' +
                        '</div>' +
                    '</div>' +
                    '<div class="settings-field" style="margin-top:10px;">' +
                        '<label class="settings-label">Body <span style="color:var(--text-muted);font-weight:normal;">(SQL, typically a BEGIN … END block)</span></label>' +
                        '<div class="mini-editor-wrap" style="min-height:180px;">' +
                            '<div class="mini-editor-backdrop"><div class="mini-editor-highlight" id="trg-body-highlight"></div></div>' +
                            '<textarea id="trg-body" rows="10" style="font-family:var(--font-mono);font-size:var(--font-size-xs);line-height:1.5;width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-md);resize:vertical;white-space:pre-wrap;word-break:break-word;min-height:180px;" spellcheck="false" placeholder="BEGIN\n    -- your trigger logic\nEND"></textarea>' +
                        '</div>' +
                        '<div class="settings-hint">References: <code>NEW.col</code> for new values, <code>OLD.col</code> for old. Wrap multi-statement bodies in <code>BEGIN … END</code>.</div>' +
                    '</div>' +
                    '<div id="trg-err" class="error-box" style="margin-top:10px;display:none;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                '</div>' +
                '<div class="modal-footer">' +
                    '<button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button>' +
                    '<button class="btn btn-primary modal-btn" id="trg-save">' + (mode === 'create' ? 'Create' : 'Save') + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

        var nameEl = overlay.querySelector('#trg-name');
        var timingEl = overlay.querySelector('#trg-timing');
        var eventEl = overlay.querySelector('#trg-event');
        var bodyEl = overlay.querySelector('#trg-body');
        var errEl = overlay.querySelector('#trg-err');

        // Manual syntax highlighting sync
        var highlightEl = document.getElementById('trg-body-highlight');
        function syncHighlight() {
            if (bodyEl && highlightEl && typeof Ledger !== 'undefined') {
                var tokens = Ledger.tokenize(bodyEl.value);
                tokens = Ledger.resolveTableNames(tokens);
                highlightEl.innerHTML = Ledger.renderTokens(tokens) + '\n';
            }
        }
        if (bodyEl) {
            bodyEl.addEventListener('input', syncHighlight);
            bodyEl.addEventListener('scroll', function() {
                if (highlightEl && highlightEl.parentNode) {
                    highlightEl.parentNode.scrollTop = bodyEl.scrollTop;
                }
            });
        }

        if (mode === 'edit' && data) {
            nameEl.value = data.name;
            timingEl.value = data.timing;
            eventEl.value = data.event;
            bodyEl.value = data.body;
            syncHighlight();
        } else {
            bodyEl.value = 'BEGIN\n    \nEND';
            syncHighlight();
        }
        nameEl.focus();

        function close() {
            overlay.classList.remove('modal-visible');
            setTimeout(function() { overlay.remove(); }, 150);
        }
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay || (e.target.closest && e.target.closest('[data-action="cancel"]'))) close();
        });

        overlay.querySelector('#trg-save').addEventListener('click', function() {
            errEl.style.display = 'none';
            var name = nameEl.value.trim();
            var body = bodyEl.value.trim();
            if (!name || !body) {
                errEl.textContent = 'Name and body are required.';
                errEl.style.display = '';
                return;
            }

            var fd = new FormData();
            fd.append('action', mode === 'create' ? 'create_trigger' : 'replace_trigger');
            fd.append('db', db);
            fd.append('table', table);
            fd.append('name', name);
            fd.append('timing', timingEl.value);
            fd.append('event', eventEl.value);
            fd.append('body', body);
            fd.append('_csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
            if (mode === 'edit') fd.append('orig_name', data.name);

            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.error) {
                        errEl.textContent = resp.error;
                        errEl.style.display = '';
                        return;
                    }
                    Ledger.setStatus('Trigger ' + (mode === 'create' ? 'created' : 'updated') + '.');
                    close();
                    window.location.reload();
                });
        });
    }

    var addBtn = document.getElementById('trigger-add-btn');
    if (addBtn) addBtn.addEventListener('click', function() { openTriggerModal('create'); });

    panel.querySelectorAll('.trigger-item').forEach(function(item) {
        var editBtn = item.querySelector('.trigger-edit-btn');
        var dropBtn = item.querySelector('.trigger-drop-btn');

        if (editBtn) editBtn.addEventListener('click', function() {
            openTriggerModal('edit', {
                name:   item.dataset.name,
                timing: item.dataset.timing,
                event:  item.dataset.event,
                body:   item.dataset.body,
            });
        });

        if (dropBtn) dropBtn.addEventListener('click', function() {
            var name = item.dataset.name;
            Ledger.confirm({
                title: 'Drop trigger',
                message: 'Permanently delete trigger `' + name + '`?',
                confirmText: 'Drop',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'drop_trigger');
                fd.append('db', db);
                fd.append('name', name);
                fd.append('_csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.error) { Ledger.setStatus('Error: ' + resp.error); return; }
                        Ledger.setStatus('Trigger dropped.');
                        window.location.reload();
                    });
            });
        });
    });
});
</script>

<?php endif; ?>

<!-- ── Routines Panel (database-level) ── -->
<?php if ($currentDb): ?>
<div class="panel-section" style="margin-top:20px;">
    <?php
    $routines = [];
    try {
        $routines = $dbInstance->getRoutines($currentDb);
    } catch (Exception $e) {}
    $procedures = array_filter($routines, function($r) { return strtoupper($r['type']) === 'PROCEDURE'; });
    $functions  = array_filter($routines, function($r) { return strtoupper($r['type']) === 'FUNCTION'; });
    ?>
    <div class="panel-section-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:8px;"><?= icon('code', 14) ?> Routines</span>
        <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:var(--font-size-xs);color:var(--text-muted);">
                <?= count($procedures) ?> proc, <?= count($functions) ?> func
            </span>
            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
            <button type="button" class="btn btn-primary btn-sm" id="rtn-add-proc"><?= icon('plus', 11) ?> Procedure</button>
            <button type="button" class="btn btn-ghost btn-sm" id="rtn-add-func"><?= icon('plus', 11) ?> Function</button>
            <?php endif; ?>
        </div>
    </div>
    <div style="padding:0;">
        <?php if (empty($routines)): ?>
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:var(--font-size-sm);">
            No stored procedures or functions in this database.
        </div>
        <?php else: ?>
        <table class="data-table" style="margin:0;font-size:var(--font-size-sm);">
            <thead>
                <tr>
                    <th style="width:50px;">Type</th>
                    <th>Name</th>
                    <th>Parameters</th>
                    <th>Returns</th>
                    <th>Definer</th>
                    <th>Modified</th>
                    <th style="width:110px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routines as $r):
                $isProcedure = strtoupper($r['type']) === 'PROCEDURE';
                try { $params = $dbInstance->getRoutineParams($currentDb, $r['name']); } catch(Exception $e) { $params = []; }
                $paramStrs = [];
                foreach ($params as $p) {
                    if ($p['name']) $paramStrs[] = ($p['mode'] ? strtoupper($p['mode']) . ' ' : '') . $p['name'] . ' ' . ($p['full_type'] ?: $p['type']);
                }
            ?>
                <tr>
                    <td>
                        <span style="font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;background:<?= $isProcedure ? 'rgba(96,165,250,0.1)' : 'rgba(192,132,252,0.1)' ?>;color:<?= $isProcedure ? 'var(--info)' : 'var(--purple,#c084fc)' ?>;font-family:var(--font-mono);">
                            <?= $isProcedure ? 'PROC' : 'FUNC' ?>
                        </span>
                    </td>
                    <td style="font-family:var(--font-mono);font-weight:600;"><?= h($r['name']) ?></td>
                    <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= $paramStrs ? h(implode(', ', $paramStrs)) : '<span style="color:var(--text-muted);">—</span>' ?>
                    </td>
                    <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-muted);">
                        <?= $isProcedure ? '—' : h($r['returns_type'] ?: '—') ?>
                    </td>
                    <td style="font-size:var(--font-size-xs);color:var(--text-muted);"><?= h($r['definer'] ?? '') ?></td>
                    <td style="font-size:var(--font-size-xs);color:var(--text-muted);"><?= h($r['modified'] ?? $r['created'] ?? '') ?></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <button type="button" class="btn btn-ghost btn-sm rtn-view" data-name="<?= h($r['name']) ?>" data-type="<?= h($r['type']) ?>"><?= icon('eye', 11) ?></button>
                            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
                            <button type="button" class="btn btn-ghost btn-sm rtn-edit" data-name="<?= h($r['name']) ?>" data-type="<?= h($r['type']) ?>"><?= icon('edit', 11) ?></button>
                            <button type="button" class="btn btn-danger btn-sm rtn-drop" data-name="<?= h($r['name']) ?>" data-type="<?= h($r['type']) ?>"><?= icon('x', 11) ?></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = <?= json_encode($currentDb) ?>;
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }

    function openRoutineEditor(type, editName) {
        // Close any open modal
        var old = document.getElementById('ledger-modal');
        if (old) old.remove();

        type = (type || 'PROCEDURE').toUpperCase();
        var isFunc = (type === 'FUNCTION');
        var mode = editName ? 'edit' : 'create';
        var title = (mode === 'create' ? 'Create ' : 'Edit ') + (isFunc ? 'Function' : 'Procedure');

        var skeleton = isFunc
            ? 'CREATE FUNCTION my_function(param1 INT)\nRETURNS VARCHAR(255)\nDETERMINISTIC\nBEGIN\n    DECLARE result VARCHAR(255);\n    SET result = CONCAT(\'Value: \', param1);\n    RETURN result;\nEND'
            : 'CREATE PROCEDURE my_procedure(IN param1 INT, OUT result VARCHAR(255))\nBEGIN\n    SELECT name INTO result FROM my_table WHERE id = param1;\nEND';

        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';
        overlay.innerHTML =
            '<div class="modal-box" style="max-width:700px;">' +
                '<div class="modal-header"><span class="modal-title">' + title + '</span><button class="modal-close" id="rtn-cancel">&times;</button></div>' +
                '<div class="modal-body">' +
                    '<div id="rtn-err" class="error-box" style="display:none;margin-bottom:12px;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                    '<label class="settings-label" style="margin-bottom:4px;">SQL Definition</label>' +
                    '<div style="margin-bottom:8px;">' +
                        '<div class="mini-editor-wrap" style="min-height:220px;">' +
                            '<div class="mini-editor-backdrop"><div class="mini-editor-highlight" id="rtn-sql-highlight"></div></div>' +
                            '<textarea id="rtn-sql" rows="14" spellcheck="false" style="font-family:var(--font-mono);font-size:var(--font-size-xs);min-height:220px;resize:vertical;width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-md);line-height:1.5;tab-size:4;white-space:pre-wrap;word-break:break-word;"></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<p style="font-size:var(--font-size-xs);color:var(--text-muted);margin:0;">Write the full CREATE statement. Do not include DELIMITER.</p>' +
                '</div>' +
                '<div class="modal-footer"><button class="btn btn-ghost modal-btn" id="rtn-cancel2">Cancel</button><button class="btn btn-primary modal-btn" id="rtn-save">' + (mode === 'create' ? 'Create' : 'Save Changes') + '</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

        var sqlEl = document.getElementById('rtn-sql');
        var errEl = document.getElementById('rtn-err');
        var rtnHighlight = document.getElementById('rtn-sql-highlight');

        function syncRtnHighlight() {
            if (sqlEl && rtnHighlight && typeof Ledger !== 'undefined') {
                var tokens = Ledger.tokenize(sqlEl.value);
                tokens = Ledger.resolveTableNames(tokens);
                rtnHighlight.innerHTML = Ledger.renderTokens(tokens) + '\n';
            }
        }
        sqlEl.addEventListener('input', syncRtnHighlight);
        sqlEl.addEventListener('scroll', function() {
            if (rtnHighlight && rtnHighlight.parentNode) rtnHighlight.parentNode.scrollTop = sqlEl.scrollTop;
        });

        // Load definition for edit mode, or set skeleton
        if (mode === 'edit' && editName) {
            sqlEl.value = 'Loading…';
            sqlEl.disabled = true;

            fetch('ajax.php?action=get_routine_definition&db=' + encodeURIComponent(db) + '&name=' + encodeURIComponent(editName) + '&type=' + encodeURIComponent(type))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    sqlEl.disabled = false;
                    sqlEl.value = data.definition || skeleton;
                    syncRtnHighlight();
                    sqlEl.focus();
                });
        } else {
            sqlEl.value = skeleton;
            syncRtnHighlight();
            sqlEl.focus();
        }

        // Save handler
        document.getElementById('rtn-save').addEventListener('click', function() {
            var sql = sqlEl.value.trim();
            if (!sql) { errEl.textContent = 'SQL definition is required.'; errEl.style.display = ''; return; }
            errEl.style.display = 'none';

            var saveBtn = this;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';

            // If editing, drop old first
            var chain = Promise.resolve();
            if (mode === 'edit' && editName) {
                chain = chain.then(function() {
                    var fd = new FormData();
                    fd.append('action', 'drop_routine');
                    fd.append('name', editName);
                    fd.append('type', type);
                    fd.append('_csrf_token', csrf());
                    return fetch('ajax.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
                });
            }

            chain.then(function() {
                var fd = new FormData();
                fd.append('action', 'create_routine');
                fd.append('sql', sql);
                fd.append('db', db);
                fd.append('_csrf_token', csrf());
                return fetch('ajax.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
            }).then(function(data) {
                saveBtn.disabled = false;
                if (data.error) {
                    errEl.style.display = '';
                    errEl.textContent = data.error;
                    if (mode === 'edit') errEl.textContent += ' (Original was dropped — paste the old definition and re-create manually if needed.)';
                    saveBtn.textContent = mode === 'create' ? 'Create' : 'Save Changes';
                } else {
                    closeModal();
                    window.location.reload();
                }
            }).catch(function(err) {
                saveBtn.disabled = false;
                saveBtn.textContent = mode === 'create' ? 'Create' : 'Save Changes';
                errEl.style.display = '';
                errEl.textContent = 'Network error: ' + err.message;
            });
        });

        function closeModal() {
            overlay.classList.remove('modal-visible');
            setTimeout(function() { overlay.remove(); }, 150);
        }
        document.getElementById('rtn-cancel').addEventListener('click', closeModal);
        document.getElementById('rtn-cancel2').addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
    }

    // Create buttons
    var addProc = document.getElementById('rtn-add-proc');
    var addFunc = document.getElementById('rtn-add-func');
    if (addProc) addProc.addEventListener('click', function() { openRoutineEditor('PROCEDURE'); });
    if (addFunc) addFunc.addEventListener('click', function() { openRoutineEditor('FUNCTION'); });

    // View definition
    document.querySelectorAll('.rtn-view').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.dataset.name, type = this.dataset.type;
            var old = document.getElementById('ledger-modal');
            if (old) old.remove();

            fetch('ajax.php?action=get_routine_definition&db=' + encodeURIComponent(db) + '&name=' + encodeURIComponent(name) + '&type=' + encodeURIComponent(type))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var def = data.definition || 'Could not retrieve definition.';
                    var overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    overlay.id = 'ledger-modal';
                    overlay.innerHTML =
                        '<div class="modal-box" style="max-width:750px;">' +
                            '<div class="modal-header"><span class="modal-title">' + name + '</span><button class="modal-close" id="rtn-view-close">&times;</button></div>' +
                            '<div class="modal-body" style="padding:0;">' +
                                '<div class="mini-editor-wrap" style="min-height:300px;border-radius:0;">' +
                                    '<div class="mini-editor-backdrop" style="border-radius:0;border-left:none;border-right:none;"><div class="mini-editor-highlight" id="rtn-view-highlight"></div></div>' +
                                    '<textarea id="rtn-view-sql" readonly rows="18" spellcheck="false" style="font-family:var(--font-mono);font-size:var(--font-size-xs);width:100%;min-height:300px;max-height:500px;padding:8px 12px;border:none;border-top:1px solid var(--border);border-bottom:1px solid var(--border);resize:vertical;line-height:1.5;cursor:text;white-space:pre-wrap;word-break:break-word;"></textarea>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button class="btn btn-ghost modal-btn" id="rtn-view-copy">Copy</button>' +
                                '<button class="btn btn-ghost modal-btn" id="rtn-view-close2">Close</button>' +
                            '</div>' +
                        '</div>';
                    document.body.appendChild(overlay);
                    requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

                    var sqlEl = document.getElementById('rtn-view-sql');
                    var viewHighlight = document.getElementById('rtn-view-highlight');
                    sqlEl.value = def;

                    // Manual sync
                    if (viewHighlight && typeof Ledger !== 'undefined') {
                        var tokens = Ledger.tokenize(def);
                        tokens = Ledger.resolveTableNames(tokens);
                        viewHighlight.innerHTML = Ledger.renderTokens(tokens) + '\n';
                    }
                    sqlEl.addEventListener('scroll', function() {
                        if (viewHighlight && viewHighlight.parentNode) viewHighlight.parentNode.scrollTop = sqlEl.scrollTop;
                    });

                    // Copy button
                    document.getElementById('rtn-view-copy').addEventListener('click', function() {
                        navigator.clipboard.writeText(def).then(function() {
                            Ledger.setStatus('Copied to clipboard.');
                        });
                    });

                    function closeView() { overlay.classList.remove('modal-visible'); setTimeout(function() { overlay.remove(); }, 150); }
                    document.getElementById('rtn-view-close').addEventListener('click', closeView);
                    document.getElementById('rtn-view-close2').addEventListener('click', closeView);
                    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeView(); });
                });
        });
    });

    // Edit
    document.querySelectorAll('.rtn-edit').forEach(function(btn) {
        btn.addEventListener('click', function() { openRoutineEditor(this.dataset.type, this.dataset.name); });
    });

    // Drop
    document.querySelectorAll('.rtn-drop').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.dataset.name, type = this.dataset.type;
            var label = type.charAt(0).toUpperCase() + type.slice(1).toLowerCase();
            Ledger.confirm({
                title: 'Drop ' + label,
                message: 'Are you sure you want to drop ' + label.toLowerCase() + ' "' + name + '"? This cannot be undone.',
                confirmText: 'Drop',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'drop_routine');
                fd.append('name', name);
                fd.append('type', type);
                fd.append('_csrf_token', csrf());
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) { if (data.success) window.location.reload(); });
            });
        });
    });
});
</script>
<?php endif; ?>

<!-- ── Events Panel (database-level) ── -->
<?php if ($currentDb): ?>
<div class="panel-section" style="margin-top:20px;">
    <?php
    $events = [];
    $schedulerStatus = 'OFF';
    try {
        $events = $dbInstance->getEvents($currentDb);
        $schedulerStatus = $dbInstance->getEventSchedulerStatus();
    } catch (Exception $e) {}
    $enabled  = array_filter($events, function($e) { return strtoupper($e['status']) === 'ENABLED'; });
    $disabled = array_filter($events, function($e) { return strtoupper($e['status']) !== 'ENABLED'; });
    ?>
    <div class="panel-section-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:8px;"><?= icon('clock', 14) ?> Events</span>
        <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:var(--font-size-xs);color:var(--text-muted);">
                <?= count($enabled) ?> enabled, <?= count($disabled) ?> disabled
            </span>
            <span style="font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;background:<?= $schedulerStatus === 'ON' ? 'rgba(74,222,128,0.1)' : 'rgba(239,68,68,0.1)' ?>;color:<?= $schedulerStatus === 'ON' ? 'var(--success,#4ade80)' : 'var(--danger,#ef4444)' ?>;font-family:var(--font-mono);" title="MySQL event_scheduler variable">
                scheduler: <?= h($schedulerStatus) ?>
            </span>
            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
            <button type="button" class="btn btn-primary btn-sm" id="evt-add"><?= icon('plus', 11) ?> Event</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($schedulerStatus !== 'ON' && !empty($events)): ?>
    <div style="padding:10px 16px;background:rgba(239,68,68,0.06);border-top:1px solid var(--border);border-bottom:1px solid var(--border);font-size:var(--font-size-xs);color:var(--text-secondary);display:flex;align-items:flex-start;gap:8px;">
        <span style="color:var(--danger,#ef4444);font-weight:700;flex-shrink:0;">⚠</span>
        <span>
            <strong>The MySQL event scheduler is <?= h($schedulerStatus) ?>.</strong>
            Events defined here will not fire automatically. To enable it globally, run
            <code style="font-family:var(--font-mono);background:var(--bg-panel);padding:1px 5px;border-radius:3px;">SET GLOBAL event_scheduler = ON;</code>
            or set <code style="font-family:var(--font-mono);background:var(--bg-panel);padding:1px 5px;border-radius:3px;">event_scheduler=ON</code> in my.cnf.
        </span>
    </div>
    <?php endif; ?>

    <div style="padding:0;">
        <?php if (empty($events)): ?>
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:var(--font-size-sm);">
            No scheduled events in this database.
        </div>
        <?php else: ?>
        <table class="data-table" style="margin:0;font-size:var(--font-size-sm);">
            <thead>
                <tr>
                    <th style="width:80px;">Status</th>
                    <th>Name</th>
                    <th style="width:90px;">Type</th>
                    <th>Schedule</th>
                    <th>Last Executed</th>
                    <th>Modified</th>
                    <th style="width:150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev):
                $isEnabled = strtoupper($ev['status']) === 'ENABLED';
                $isRecurring = strtoupper($ev['type']) === 'RECURRING';
                if ($isRecurring) {
                    $intervalVal = $ev['interval_value'] ?? '';
                    $intervalField = strtoupper($ev['interval_field'] ?? '');
                    $schedule = 'EVERY ' . h($intervalVal) . ' ' . h($intervalField);
                    if (!empty($ev['starts'])) $schedule .= '<br><span style="color:var(--text-muted);">starts ' . h($ev['starts']) . '</span>';
                    if (!empty($ev['ends']))   $schedule .= '<br><span style="color:var(--text-muted);">ends ' . h($ev['ends']) . '</span>';
                } else {
                    $schedule = 'AT ' . h($ev['execute_at'] ?? '—');
                }
                $statusColor = $isEnabled ? 'var(--success,#4ade80)' : 'var(--text-muted)';
                $statusBg    = $isEnabled ? 'rgba(74,222,128,0.1)' : 'rgba(148,163,184,0.1)';
            ?>
                <tr>
                    <td>
                        <span style="font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;background:<?= $statusBg ?>;color:<?= $statusColor ?>;font-family:var(--font-mono);">
                            <?= h($ev['status']) ?>
                        </span>
                    </td>
                    <td style="font-family:var(--font-mono);font-weight:600;"><?= h($ev['name']) ?></td>
                    <td>
                        <span style="font-size:10px;padding:2px 6px;border-radius:3px;font-weight:700;background:<?= $isRecurring ? 'rgba(96,165,250,0.1)' : 'rgba(192,132,252,0.1)' ?>;color:<?= $isRecurring ? 'var(--info)' : 'var(--purple,#c084fc)' ?>;font-family:var(--font-mono);">
                            <?= $isRecurring ? 'RECURRING' : 'ONE TIME' ?>
                        </span>
                    </td>
                    <td style="font-family:var(--font-mono);font-size:var(--font-size-xs);color:var(--text-secondary);">
                        <?= $schedule ?>
                    </td>
                    <td style="font-size:var(--font-size-xs);color:var(--text-muted);">
                        <?= !empty($ev['last_executed']) ? h($ev['last_executed']) : '<span style="color:var(--text-muted);">never</span>' ?>
                    </td>
                    <td style="font-size:var(--font-size-xs);color:var(--text-muted);"><?= h($ev['modified'] ?? $ev['created'] ?? '') ?></td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <button type="button" class="btn btn-ghost btn-sm evt-view" data-name="<?= h($ev['name']) ?>" title="View definition"><?= icon('eye', 11) ?></button>
                            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
                            <button type="button" class="btn btn-ghost btn-sm evt-toggle" data-name="<?= h($ev['name']) ?>" data-enabled="<?= $isEnabled ? '1' : '0' ?>" title="<?= $isEnabled ? 'Disable' : 'Enable' ?>">
                                <?= icon($isEnabled ? 'x' : 'play', 11) ?>
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm evt-edit" data-name="<?= h($ev['name']) ?>" title="Edit"><?= icon('edit', 11) ?></button>
                            <button type="button" class="btn btn-danger btn-sm evt-drop" data-name="<?= h($ev['name']) ?>" title="Drop"><?= icon('trash', 11) ?></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var db = <?= json_encode($currentDb) ?>;
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }

    function openEventEditor(editName) {
        var old = document.getElementById('ledger-modal');
        if (old) old.remove();

        var mode = editName ? 'edit' : 'create';
        var title = mode === 'create' ? 'Create Event' : 'Edit Event';

        var skeleton =
            'CREATE EVENT my_event\n' +
            'ON SCHEDULE EVERY 1 DAY\n' +
            'STARTS CURRENT_TIMESTAMP\n' +
            'ON COMPLETION PRESERVE\n' +
            'ENABLE\n' +
            'COMMENT \'Daily maintenance task\'\n' +
            'DO\n' +
            'BEGIN\n' +
            '    -- Your SQL here, e.g.:\n' +
            '    DELETE FROM sessions WHERE last_seen < NOW() - INTERVAL 30 DAY;\n' +
            'END';

        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';
        overlay.innerHTML =
            '<div class="modal-box" style="max-width:720px;">' +
                '<div class="modal-header"><span class="modal-title">' + title + '</span><button class="modal-close" id="evt-cancel">&times;</button></div>' +
                '<div class="modal-body">' +
                    '<div id="evt-err" class="error-box" style="display:none;margin-bottom:12px;font-size:var(--font-size-xs);padding:8px 12px;"></div>' +
                    '<label class="settings-label" style="margin-bottom:4px;">SQL Definition</label>' +
                    '<div style="margin-bottom:8px;">' +
                        '<div class="mini-editor-wrap" style="min-height:260px;">' +
                            '<div class="mini-editor-backdrop"><div class="mini-editor-highlight" id="evt-sql-highlight"></div></div>' +
                            '<textarea id="evt-sql" rows="16" spellcheck="false" style="font-family:var(--font-mono);font-size:var(--font-size-xs);min-height:260px;resize:vertical;width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-md);line-height:1.5;tab-size:4;white-space:pre-wrap;word-break:break-word;"></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<p style="font-size:var(--font-size-xs);color:var(--text-muted);margin:0;">' +
                        'Write the full <code>CREATE EVENT</code> statement. Use <code>ON SCHEDULE EVERY ... </code> for recurring or <code>ON SCHEDULE AT ...</code> for one-time. ' +
                        '<code>ON COMPLETION PRESERVE</code> keeps the event after its last run; omit to auto-drop.' +
                    '</p>' +
                '</div>' +
                '<div class="modal-footer"><button class="btn btn-ghost modal-btn" id="evt-cancel2">Cancel</button><button class="btn btn-primary modal-btn" id="evt-save">' + (mode === 'create' ? 'Create' : 'Save Changes') + '</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

        var sqlEl = document.getElementById('evt-sql');
        var errEl = document.getElementById('evt-err');
        var highlight = document.getElementById('evt-sql-highlight');

        function syncHighlight() {
            if (sqlEl && highlight && typeof Ledger !== 'undefined') {
                var tokens = Ledger.tokenize(sqlEl.value);
                tokens = Ledger.resolveTableNames(tokens);
                highlight.innerHTML = Ledger.renderTokens(tokens) + '\n';
            }
        }
        sqlEl.addEventListener('input', syncHighlight);
        sqlEl.addEventListener('scroll', function() {
            if (highlight && highlight.parentNode) highlight.parentNode.scrollTop = sqlEl.scrollTop;
        });

        if (mode === 'edit' && editName) {
            sqlEl.value = 'Loading…';
            sqlEl.disabled = true;
            fetch('ajax.php?action=get_event_definition&db=' + encodeURIComponent(db) + '&name=' + encodeURIComponent(editName))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    sqlEl.disabled = false;
                    sqlEl.value = data.definition || skeleton;
                    syncHighlight();
                    sqlEl.focus();
                });
        } else {
            sqlEl.value = skeleton;
            syncHighlight();
            sqlEl.focus();
        }

        document.getElementById('evt-save').addEventListener('click', function() {
            var sql = sqlEl.value.trim();
            if (!sql) { errEl.textContent = 'SQL definition is required.'; errEl.style.display = ''; return; }
            errEl.style.display = 'none';
            var saveBtn = this;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';

            var chain = Promise.resolve();
            if (mode === 'edit' && editName) {
                chain = chain.then(function() {
                    var fd = new FormData();
                    fd.append('action', 'drop_event');
                    fd.append('name', editName);
                    fd.append('db', db);
                    fd.append('_csrf_token', csrf());
                    return fetch('ajax.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
                });
            }

            chain.then(function() {
                var fd = new FormData();
                fd.append('action', 'create_event');
                fd.append('sql', sql);
                fd.append('db', db);
                fd.append('_csrf_token', csrf());
                return fetch('ajax.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
            }).then(function(data) {
                saveBtn.disabled = false;
                if (data.error) {
                    errEl.style.display = '';
                    errEl.textContent = data.error;
                    if (mode === 'edit') errEl.textContent += ' (Original was dropped — paste the old definition and re-create manually if needed.)';
                    saveBtn.textContent = mode === 'create' ? 'Create' : 'Save Changes';
                } else {
                    closeModal();
                    window.location.reload();
                }
            }).catch(function(err) {
                saveBtn.disabled = false;
                saveBtn.textContent = mode === 'create' ? 'Create' : 'Save Changes';
                errEl.style.display = '';
                errEl.textContent = 'Network error: ' + err.message;
            });
        });

        function closeModal() {
            overlay.classList.remove('modal-visible');
            setTimeout(function() { overlay.remove(); }, 150);
        }
        document.getElementById('evt-cancel').addEventListener('click', closeModal);
        document.getElementById('evt-cancel2').addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
    }

    // Create button
    var addBtn = document.getElementById('evt-add');
    if (addBtn) addBtn.addEventListener('click', function() { openEventEditor(); });

    // View definition
    document.querySelectorAll('.evt-view').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.dataset.name;
            var old = document.getElementById('ledger-modal');
            if (old) old.remove();

            fetch('ajax.php?action=get_event_definition&db=' + encodeURIComponent(db) + '&name=' + encodeURIComponent(name))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var def = data.definition || 'Could not retrieve definition.';
                    var overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    overlay.id = 'ledger-modal';
                    overlay.innerHTML =
                        '<div class="modal-box" style="max-width:750px;">' +
                            '<div class="modal-header"><span class="modal-title">' + name + '</span><button class="modal-close" id="evt-view-close">&times;</button></div>' +
                            '<div class="modal-body" style="padding:0;">' +
                                '<div class="mini-editor-wrap" style="min-height:300px;border-radius:0;">' +
                                    '<div class="mini-editor-backdrop" style="border-radius:0;border-left:none;border-right:none;"><div class="mini-editor-highlight" id="evt-view-highlight"></div></div>' +
                                    '<textarea id="evt-view-sql" readonly rows="18" spellcheck="false" style="font-family:var(--font-mono);font-size:var(--font-size-xs);width:100%;min-height:300px;max-height:500px;padding:8px 12px;border:none;border-top:1px solid var(--border);border-bottom:1px solid var(--border);resize:vertical;line-height:1.5;cursor:text;white-space:pre-wrap;word-break:break-word;"></textarea>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button class="btn btn-ghost modal-btn" id="evt-view-copy">Copy</button>' +
                                '<button class="btn btn-ghost modal-btn" id="evt-view-close2">Close</button>' +
                            '</div>' +
                        '</div>';
                    document.body.appendChild(overlay);
                    requestAnimationFrame(function() { overlay.classList.add('modal-visible'); });

                    var sqlEl = document.getElementById('evt-view-sql');
                    var viewHighlight = document.getElementById('evt-view-highlight');
                    sqlEl.value = def;

                    if (viewHighlight && typeof Ledger !== 'undefined') {
                        var tokens = Ledger.tokenize(def);
                        tokens = Ledger.resolveTableNames(tokens);
                        viewHighlight.innerHTML = Ledger.renderTokens(tokens) + '\n';
                    }
                    sqlEl.addEventListener('scroll', function() {
                        if (viewHighlight && viewHighlight.parentNode) viewHighlight.parentNode.scrollTop = sqlEl.scrollTop;
                    });

                    document.getElementById('evt-view-copy').addEventListener('click', function() {
                        navigator.clipboard.writeText(def).then(function() {
                            Ledger.setStatus('Copied to clipboard.');
                        });
                    });

                    function closeView() { overlay.classList.remove('modal-visible'); setTimeout(function() { overlay.remove(); }, 150); }
                    document.getElementById('evt-view-close').addEventListener('click', closeView);
                    document.getElementById('evt-view-close2').addEventListener('click', closeView);
                    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeView(); });
                });
        });
    });

    // Edit
    document.querySelectorAll('.evt-edit').forEach(function(btn) {
        btn.addEventListener('click', function() { openEventEditor(this.dataset.name); });
    });

    // Toggle enable/disable
    document.querySelectorAll('.evt-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.dataset.name;
            var isEnabled = this.dataset.enabled === '1';
            var newStatus = isEnabled ? 'DISABLE' : 'ENABLE';
            var fd = new FormData();
            fd.append('action', 'toggle_event_status');
            fd.append('name', name);
            fd.append('status', newStatus);
            fd.append('db', db);
            fd.append('_csrf_token', csrf());
            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        Ledger.alert({ title: 'Error', message: data.error });
                    } else {
                        Ledger.setStatus('Event ' + (isEnabled ? 'disabled' : 'enabled') + '.');
                        window.location.reload();
                    }
                });
        });
    });

    // Drop
    document.querySelectorAll('.evt-drop').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.dataset.name;
            Ledger.confirm({
                title: 'Drop Event',
                message: 'Are you sure you want to drop event "' + name + '"? This cannot be undone.',
                confirmText: 'Drop',
                danger: true,
            }).then(function(ok) {
                if (!ok) return;
                var fd = new FormData();
                fd.append('action', 'drop_event');
                fd.append('name', name);
                fd.append('db', db);
                fd.append('_csrf_token', csrf());
                fetch('ajax.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) {
                            Ledger.alert({ title: 'Error', message: data.error });
                        } else {
                            Ledger.setStatus('Event dropped.');
                            window.location.reload();
                        }
                    });
            });
        });
    });
});
</script>
<?php endif; ?>
