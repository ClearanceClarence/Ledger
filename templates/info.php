<?php if (!$currentDb || !$currentTable): ?>
<div class="error-box">Select a table to view its info.</div>
<?php return; endif; ?>

<?php
try {
    $columns = $dbInstance->getColumns($currentDb, $currentTable);
    $tableStatus = $dbInstance->getTables($currentDb);
    $thisTable = null;
    foreach ($tableStatus as $t) {
        if ($t['Name'] === $currentTable) { $thisTable = $t; break; }
    }
    $exactRows = $dbInstance->getExactRowCount($currentDb, $currentTable);
} catch (Exception $e) {
    echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
    return;
}

$primaryKey = '—';
$indexCount = 0;
foreach ($columns as $col) {
    if ($col['Key'] === 'PRI') $primaryKey = $col['Field'];
    if (!empty($col['Key'])) $indexCount++;
}
$dataLen = (int)($thisTable['Data_length'] ?? 0);
$idxLen = (int)($thisTable['Index_length'] ?? 0);
$totalSize = $dataLen + $idxLen;
?>

<!-- Header -->
<div class="info-header info-header-green">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('table', 24) ?></div>
        <div>
            <h3 class="info-header-title"><?= h($currentTable) ?></h3>
            <span class="info-header-sub"><?= h($currentDb) ?> · <?= h($thisTable['Engine'] ?? '—') ?> · <?= h($thisTable['Collation'] ?? '—') ?></span>
        </div>
    </div>
    <div class="info-header-stats">
        <div class="info-stat">
            <span class="info-stat-value accent"><?= format_number($exactRows) ?></span>
            <span class="info-stat-label">Rows</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value gold"><?= count($columns) ?></span>
            <span class="info-stat-label">Columns</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value purple"><?= $indexCount ?></span>
            <span class="info-stat-label">Indexes</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value info"><?= format_bytes($totalSize) ?></span>
            <span class="info-stat-label">Total Size</span>
        </div>
    </div>
</div>

<!-- Details Grid -->
<div class="info-details">

    <!-- Table Properties -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('database', 14) ?> Properties</div>
        <table class="info-table">
            <tr><td class="info-table-key">Engine</td><td class="info-table-val"><?= h($thisTable['Engine'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Collation</td><td class="info-table-val"><?= h($thisTable['Collation'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Row Format</td><td class="info-table-val"><?= h($thisTable['Row_format'] ?? '—') ?></td></tr>
            <tr>
                <td class="info-table-key">Primary Key</td>
                <td class="info-table-val">
                    <code class="rel-col"><?= h($primaryKey) ?></code>
                </td>
            </tr>
            <tr>
                <td class="info-table-key">Auto Increment</td>
                <td class="info-table-val" style="color:var(--accent);font-weight:600;"><?= h($thisTable['Auto_increment'] ?? '—') ?></td>
            </tr>
            <?php if (!empty($thisTable['Comment'])): ?>
            <tr><td class="info-table-key">Comment</td><td class="info-table-val"><?= h($thisTable['Comment']) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Storage -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('layers', 14) ?> Storage</div>
        <table class="info-table">
            <tr><td class="info-table-key">Data Size</td><td class="info-table-val"><?= format_bytes($dataLen) ?></td></tr>
            <tr><td class="info-table-key">Index Size</td><td class="info-table-val"><?= format_bytes($idxLen) ?></td></tr>
            <tr><td class="info-table-key">Total Size</td><td class="info-table-val" style="font-weight:600;"><?= format_bytes($totalSize) ?></td></tr>
            <tr><td class="info-table-key">Avg Row Length</td><td class="info-table-val"><?= format_bytes((int)($thisTable['Avg_row_length'] ?? 0)) ?></td></tr>
            <tr><td class="info-table-key">Max Data Length</td><td class="info-table-val"><?= $thisTable['Max_data_length'] ? format_bytes((int)$thisTable['Max_data_length']) : '—' ?></td></tr>
        </table>
    </div>

    <!-- Timestamps -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('clock', 14) ?> Timestamps</div>
        <table class="info-table">
            <tr><td class="info-table-key">Created</td><td class="info-table-val"><?= h($thisTable['Create_time'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Last Updated</td><td class="info-table-val"><?= h($thisTable['Update_time'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Last Checked</td><td class="info-table-val"><?= h($thisTable['Check_time'] ?? '—') ?></td></tr>
        </table>
    </div>
</div>

<!-- Quick Queries -->
<h3 class="section-title" style="margin-top:28px;"><?= icon('terminal', 16) ?> Quick Queries</h3>
<div class="quick-queries">
    <?php
    $queries = [
        "SELECT * FROM `{$currentTable}`",
        "SELECT COUNT(*) FROM `{$currentTable}`",
        "DESCRIBE `{$currentTable}`",
        "SHOW CREATE TABLE `{$currentTable}`",
        "SELECT * FROM `{$currentTable}` LIMIT 10",
    ];
    foreach ($queries as $q):
    ?>
    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&tab=sql&run=1&sql=<?= urlencode($q) ?>" class="quick-query">
        <code><?= h($q) ?></code>
    </a>
    <?php endforeach; ?>
</div>

<!-- Danger Zone -->
<div class="danger-zone">
    <div class="danger-zone-header">
        <?= icon('alert-triangle', 18) ?>
        <span>Danger Zone</span>
    </div>
    <div class="danger-zone-body">
        <div class="danger-item">
            <div class="danger-item-info">
                <div class="danger-item-title"><?= icon('alert-triangle', 14) ?> Truncate Table</div>
                <div class="danger-item-desc">Remove all rows from <strong><?= h($currentTable) ?></strong>. The table structure and indexes will be preserved. AUTO_INCREMENT resets to 1. This cannot be undone.</div>
            </div>
            <button class="btn btn-danger" onclick="Ledger.confirm({
                title: 'Truncate Table',
                message: 'TRUNCATE TABLE `<?= h($currentTable) ?>`?\n\nThis will permanently delete ALL <?= format_number($exactRows) ?> rows. This cannot be undone.',
                confirmText: 'Truncate All Rows',
                cancelText: 'Cancel',
                danger: true
            }).then(function(ok){ if(ok) window.location='?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&action=truncate'; })">
                <?= icon('alert-triangle', 14) ?> Truncate Table
            </button>
        </div>
        <div class="danger-divider"></div>
        <div class="danger-item">
            <div class="danger-item-info">
                <div class="danger-item-title"><?= icon('trash', 14) ?> Drop Table</div>
                <div class="danger-item-desc">Permanently delete <strong><?= h($currentTable) ?></strong> including all data, indexes, and foreign key relationships. This cannot be undone.</div>
            </div>
            <button class="btn btn-danger" onclick="Ledger.confirm({
                title: 'Drop Table',
                message: 'DROP TABLE `<?= h($currentTable) ?>`?\n\nThis will permanently destroy the table, all <?= format_number($exactRows) ?> rows, and all relationships. This cannot be undone.',
                confirmText: 'Drop Table Forever',
                cancelText: 'Cancel',
                danger: true
            }).then(function(ok){ if(ok) window.location='?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&action=drop'; })">
                <?= icon('trash', 14) ?> Drop Table
            </button>
        </div>
    </div>
</div>
