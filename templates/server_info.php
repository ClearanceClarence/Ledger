<?php
try {
    $serverInfo = $dbInstance->getServerInfo();
} catch (Exception $e) {
    echo '<div class="error-box"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</div>';
    return;
}

$vars = $serverInfo['variables'];
$status = $serverInfo['status'];
$uptime = (int)($status['Uptime'] ?? 0);
$bytesIn = (int)($status['Bytes_received'] ?? 0);
$bytesOut = (int)($status['Bytes_sent'] ?? 0);
?>

<!-- Header -->
<div class="info-header">
    <div class="info-header-left">
        <div class="info-header-icon"><?= icon('server', 24) ?></div>
        <div>
            <h3 class="info-header-title"><?= h($vars['hostname'] ?? php_uname('n')) ?></h3>
            <span class="info-header-sub">MySQL <?= h($serverInfo['version']) ?> · Port <?= h($vars['port'] ?? '3306') ?> · Up <?= format_uptime($uptime) ?></span>
        </div>
    </div>
    <div class="info-header-stats">
        <div class="info-stat">
            <span class="info-stat-value accent"><?= count($databases) ?></span>
            <span class="info-stat-label">Databases</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value gold"><?= h($status['Threads_connected'] ?? '0') ?></span>
            <span class="info-stat-label">Connections</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value info"><?= format_number($status['Questions'] ?? 0) ?></span>
            <span class="info-stat-label">Queries</span>
        </div>
        <div class="info-stat">
            <span class="info-stat-value purple"><?= format_uptime($uptime) ?></span>
            <span class="info-stat-label">Uptime</span>
        </div>
    </div>
</div>

<!-- Details -->
<div class="info-details">

    <!-- MySQL -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('database', 14) ?> MySQL Server</div>
        <table class="info-table">
            <tr><td class="info-table-key">Version</td><td class="info-table-val" style="color:var(--accent);font-weight:600;"><?= h($serverInfo['version']) ?></td></tr>
            <tr><td class="info-table-key">Hostname</td><td class="info-table-val"><?= h($vars['hostname'] ?? php_uname('n')) ?></td></tr>
            <tr><td class="info-table-key">Port</td><td class="info-table-val"><?= h($vars['port'] ?? '3306') ?></td></tr>
            <tr><td class="info-table-key">Character Set</td><td class="info-table-val"><?= h($vars['character_set_server'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Collation</td><td class="info-table-val"><?= h($vars['collation_server'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Data Directory</td><td class="info-table-val" style="word-break:break-all;"><?= h($vars['datadir'] ?? '—') ?></td></tr>
        </table>
    </div>

    <!-- Performance -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('activity', 14) ?> Performance</div>
        <table class="info-table">
            <tr><td class="info-table-key">Uptime</td><td class="info-table-val" style="color:var(--purple);"><?= format_uptime($uptime) ?></td></tr>
            <tr><td class="info-table-key">Max Connections</td><td class="info-table-val"><?= h($vars['max_connections'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Active Threads</td><td class="info-table-val" style="color:var(--accent);"><?= h($status['Threads_connected'] ?? '—') ?></td></tr>
            <tr><td class="info-table-key">Total Queries</td><td class="info-table-val" style="color:var(--info);"><?= format_number($status['Questions'] ?? 0) ?></td></tr>
            <tr><td class="info-table-key">InnoDB Buffer</td><td class="info-table-val"><?= format_bytes((int)($vars['innodb_buffer_pool_size'] ?? 0)) ?></td></tr>
            <tr><td class="info-table-key">Traffic In</td><td class="info-table-val"><?= format_bytes($bytesIn) ?></td></tr>
            <tr><td class="info-table-key">Traffic Out</td><td class="info-table-val"><?= format_bytes($bytesOut) ?></td></tr>
        </table>
    </div>

    <!-- PHP -->
    <div class="info-section">
        <div class="info-section-title"><?= icon('code', 14) ?> PHP Environment</div>
        <table class="info-table">
            <tr><td class="info-table-key">PHP Version</td><td class="info-table-val" style="color:var(--accent);font-weight:600;"><?= PHP_VERSION ?></td></tr>
            <tr><td class="info-table-key">OS</td><td class="info-table-val"><?= h(php_uname('s') . ' ' . php_uname('r')) ?></td></tr>
            <tr><td class="info-table-key">SAPI</td><td class="info-table-val" style="color:var(--info);"><?= h(php_sapi_name()) ?></td></tr>
            <tr><td class="info-table-key">PDO Drivers</td><td class="info-table-val" style="color:var(--purple);"><?= h(implode(', ', PDO::getAvailableDrivers())) ?></td></tr>
            <tr><td class="info-table-key">Memory Limit</td><td class="info-table-val"><?= h(ini_get('memory_limit')) ?></td></tr>
            <tr><td class="info-table-key">Max Upload</td><td class="info-table-val"><?= h(ini_get('upload_max_filesize')) ?></td></tr>
            <tr><td class="info-table-key">Max POST Size</td><td class="info-table-val"><?= h(ini_get('post_max_size')) ?></td></tr>
        </table>
    </div>
</div>

<!-- Database List -->
<h3 class="section-title" style="margin-top:24px;"><?= icon('layers', 16) ?> All Databases</h3>
<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Database</th>
                <th>Tables</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($databases as $dbName): ?>
            <tr>
                <td>
                    <a href="?db=<?= urlencode($dbName) ?>" style="color:var(--warning);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <?= icon('database', 13) ?> <?= h($dbName) ?>
                    </a>
                </td>
                <td class="cell-number">
                    <?php
                    try {
                        echo count($dbInstance->getTables($dbName));
                    } catch (Exception $e) {
                        echo '<span class="cell-null">N/A</span>';
                    }
                    ?>
                </td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="?db=<?= urlencode($dbName) ?>&tab=browse" class="btn btn-ghost btn-sm"><?= icon('table', 12) ?> Browse</a>
                        <a href="?db=<?= urlencode($dbName) ?>&tab=sql" class="btn btn-ghost btn-sm"><?= icon('terminal', 12) ?> SQL</a>
                        <a href="?db=<?= urlencode($dbName) ?>&action=export_db" class="btn btn-ghost btn-sm"><?= icon('download', 12) ?> Export</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
