<?php
if (!$currentDb):
    // No database selected — show all databases for export
?>

<!-- Header -->
<div class="info-header info-header-amber">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('download', 24) ?></div>
        <div>
            <h3 class="info-header-title">Export</h3>
            <span class="info-header-sub">Select a database to export</span>
        </div>
    </div>
</div>

<h3 class="section-title" style="margin-top:20px;"><?= icon('layers', 16) ?> All Databases</h3>
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Database</th>
                <th>Tables</th>
                <th>Size</th>
                <th>Export</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($databases as $dbName):
                $dbSize = 0;
                $dbTableCount = 0;
                try {
                    $tList = $dbInstance->getTables($dbName);
                    $dbTableCount = count($tList);
                    foreach ($tList as $t) {
                        $dbSize += (int)($t['Data_length'] ?? 0) + (int)($t['Index_length'] ?? 0);
                    }
                } catch (Exception $e) {}
            ?>
            <tr>
                <td>
                    <a href="?tab=export&db=<?= urlencode($dbName) ?>" style="color:var(--warning);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <?= icon('database', 13) ?> <?= h($dbName) ?>
                    </a>
                </td>
                <td class="cell-number"><?= format_number($dbTableCount) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($dbSize) ?></td>
                <td>
                    <a href="?db=<?= urlencode($dbName) ?>&action=export_db" class="btn btn-primary btn-sm">
                        <?= icon('download', 12) ?> <?= h($dbName) ?>.sql
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php return; endif; ?>

<?php
// Get table info if selected
$tableInfo = null;
$tableRows = 0;
$tableSize = 0;
if ($currentTable) {
    try {
        $allTables = $dbInstance->getTables($currentDb);
        foreach ($allTables as $t) {
            if ($t['Name'] === $currentTable) {
                $tableInfo = $t;
                $tableRows = $dbInstance->getExactRowCount($currentDb, $currentTable);
                $tableSize = (int)($t['Data_length'] ?? 0) + (int)($t['Index_length'] ?? 0);
                break;
            }
        }
    } catch (Exception $e) {}
}

// Get database info
$dbTables = [];
$dbTotalRows = 0;
$dbTotalSize = 0;
try {
    $dbTables = $dbInstance->getTables($currentDb);
    foreach ($dbTables as $t) {
        $dbTotalRows += (int)($t['Rows'] ?? 0);
        $dbTotalSize += (int)($t['Data_length'] ?? 0) + (int)($t['Index_length'] ?? 0);
    }
} catch (Exception $e) {}
?>

<!-- Header -->
<div class="info-header info-header-amber">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('download', 24) ?></div>
        <div>
            <h3 class="info-header-title">Export</h3>
            <span class="info-header-sub"><?= h($currentDb) ?><?= $currentTable ? ' · ' . h($currentTable) : '' ?></span>
        </div>
    </div>
</div>

<?php if ($currentTable && $tableInfo): ?>
<!-- Table Export -->
<h3 class="section-title" style="margin-top:20px;">
    <?= icon('table', 16) ?> Table: <span class="highlight"><?= h($currentTable) ?></span>
</h3>

<div class="export-grid">
    <!-- SQL -->
    <div class="export-card">
        <div class="export-card-icon" style="color:var(--accent);"><?= icon('code', 28) ?></div>
        <div class="export-card-body">
            <div class="export-card-title">SQL Dump</div>
            <div class="export-card-desc">
                <code>CREATE TABLE</code> + <code>INSERT</code> statements.<br>
                Importable into any MySQL/MariaDB server.
            </div>
            <div class="export-card-meta">
                <span><?= icon('layers', 11) ?> <?= format_number($tableRows) ?> rows</span>
                <span><?= icon('database', 11) ?> ~<?= format_bytes($tableSize) ?></span>
            </div>
            <div class="export-mode-group" role="radiogroup" aria-label="Export contents">
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="full" checked form="export-table-form">
                    <span>Structure + data</span>
                </label>
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="structure" form="export-table-form">
                    <span>Structure only</span>
                </label>
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="data" form="export-table-form">
                    <span>Data only</span>
                </label>
            </div>
        </div>
        <form id="export-table-form" method="get" action="index.php" style="display:contents;">
            <input type="hidden" name="db" value="<?= h($currentDb) ?>">
            <input type="hidden" name="table" value="<?= h($currentTable) ?>">
            <input type="hidden" name="action" value="export_sql">
            <button type="submit" class="btn btn-primary export-btn">
                <?= icon('download', 14) ?> <?= h($currentTable) ?>.sql
            </button>
        </form>
    </div>

    <!-- CSV -->
    <div class="export-card">
        <div class="export-card-icon" style="color:var(--info);"><?= icon('file-text', 28) ?></div>
        <div class="export-card-body">
            <div class="export-card-title">CSV</div>
            <div class="export-card-desc">
                Comma-separated values with column headers.<br>
                Opens in Excel, Google Sheets, or any spreadsheet app.
            </div>
            <div class="export-card-meta">
                <span><?= icon('layers', 11) ?> <?= format_number($tableRows) ?> rows</span>
                <span><?= icon('columns', 11) ?> <?= count($dbInstance->getColumns($currentDb, $currentTable)) ?> columns</span>
            </div>
        </div>
        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($currentTable) ?>&action=export_csv"
           class="btn btn-primary export-btn">
            <?= icon('download', 14) ?> <?= h($currentTable) ?>.csv
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Database Export -->
<h3 class="section-title" style="margin-top:28px;">
    <?= icon('database', 16) ?> Database: <span class="highlight"><?= h($currentDb) ?></span>
</h3>

<div class="export-grid">
    <div class="export-card export-card-wide">
        <div class="export-card-icon" style="color:var(--warning);"><?= icon('database', 28) ?></div>
        <div class="export-card-body">
            <div class="export-card-title">Full Database SQL Dump</div>
            <div class="export-card-desc">
                Exports all <?= count($dbTables) ?> tables with <code>CREATE TABLE</code> + <code>INSERT</code> statements.
                Includes <code>CREATE DATABASE IF NOT EXISTS</code> and <code>USE</code> statements.
            </div>
            <div class="export-card-meta">
                <span><?= icon('table', 11) ?> <?= count($dbTables) ?> tables</span>
                <span><?= icon('layers', 11) ?> ~<?= format_number($dbTotalRows) ?> rows</span>
                <span><?= icon('database', 11) ?> ~<?= format_bytes($dbTotalSize) ?></span>
            </div>
            <div class="export-mode-group" role="radiogroup" aria-label="Export contents">
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="full" checked form="export-db-form">
                    <span>Structure + data</span>
                </label>
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="structure" form="export-db-form">
                    <span>Structure only</span>
                </label>
                <label class="export-mode-option">
                    <input type="radio" name="mode" value="data" form="export-db-form">
                    <span>Data only</span>
                </label>
            </div>
            <div class="export-mode-group" role="radiogroup" aria-label="Export format style" style="margin-top:8px;">
                <label class="export-mode-option">
                    <input type="radio" name="style" value="single" checked form="export-db-form">
                    <span>Single-statement</span>
                </label>
                <label class="export-mode-option">
                    <input type="radio" name="style" value="phpmyadmin" form="export-db-form">
                    <span>phpMyAdmin-compatible</span>
                </label>
            </div>
        </div>
        <form id="export-db-form" method="get" action="index.php" style="display:contents;">
            <input type="hidden" name="db" value="<?= h($currentDb) ?>">
            <input type="hidden" name="action" value="export_db">
            <button type="submit" class="btn btn-primary export-btn">
                <?= icon('download', 14) ?> <?= h($currentDb) ?>.sql
            </button>
        </form>
    </div>
</div>

<?php if (!empty($dbTables)): ?>
<!-- Tables in this database -->
<h3 class="section-title" style="margin-top:24px;"><?= icon('list', 16) ?> Tables in Database</h3>
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Table</th>
                <th>Rows</th>
                <th>Size</th>
                <th>Engine</th>
                <th>Export</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dbTables as $t):
                $tName = $t['Name'];
                $tSize = (int)($t['Data_length'] ?? 0) + (int)($t['Index_length'] ?? 0);
            ?>
            <tr>
                <td>
                    <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&tab=export" style="color:var(--info);text-decoration:none;font-weight:500;">
                        <?= icon('table', 12) ?> <?= h($tName) ?>
                    </a>
                </td>
                <td class="cell-number"><?= format_number($t['Rows'] ?? 0) ?></td>
                <td style="color:var(--text-secondary);"><?= format_bytes($tSize) ?></td>
                <td style="color:var(--text-muted);font-size:var(--font-size-xs);"><?= h($t['Engine'] ?? '') ?></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&action=export_sql" class="btn btn-ghost btn-sm"><?= icon('code', 11) ?> SQL</a>
                        <a href="?db=<?= urlencode($currentDb) ?>&table=<?= urlencode($tName) ?>&action=export_csv" class="btn btn-ghost btn-sm"><?= icon('file-text', 11) ?> CSV</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
