<?php
$favUsername = (isset($auth) && $auth->isLoggedIn()) ? $auth->getUsername() : 'anonymous';
$favorites = ledger_favorites_get($favUsername);
$favSet = [];
foreach ($favorites as $f) { $favSet[$f['db'] . '.' . $f['table']] = true; }
?>
<aside class="sidebar" id="sidebar"
       data-spacing="<?= h($_COOKIE['ledger_sidebar_spacing'] ?? 'normal') ?>"
       data-hide-counts="<?= h($_COOKIE['ledger_hide_counts'] ?? '0') ?>">
    <div class="sidebar-header">
        <?= icon('database', 13) ?>
        Databases
        <div class="sidebar-header-actions">
            <button type="button" class="sidebar-gear-btn" id="sidebar-gear" title="Sidebar settings"><?= icon('settings', 11) ?></button>
            <?php if (!(isset($auth) && $auth->isReadOnly())): ?>
            <button type="button" class="sidebar-add-btn" id="sidebar-create-db" title="Create database"><?= icon('plus', 12) ?></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar Settings (hidden) -->
    <div class="sidebar-settings" id="sidebar-settings" style="display:none;">
        <div class="sidebar-setting-row">
            <span class="sidebar-setting-label">Spacing</span>
            <div class="sidebar-setting-btns">
                <button type="button" class="sidebar-spacing-btn" data-spacing="compact" title="Compact">S</button>
                <button type="button" class="sidebar-spacing-btn" data-spacing="normal" title="Normal">M</button>
                <button type="button" class="sidebar-spacing-btn" data-spacing="expanded" title="Expanded">L</button>
            </div>
        </div>
        <div class="sidebar-setting-row">
            <label class="sidebar-setting-label sidebar-setting-check">
                <input type="checkbox" id="sidebar-hide-counts">
                Hide row counts
            </label>
        </div>
    </div>

    <div class="sidebar-content">
        <div class="sidebar-filter-wrap">
            <input type="text" class="sidebar-filter" id="sidebar-filter" placeholder="Filter tables…" autocomplete="off" spellcheck="false">
        </div>
        <?php if (!empty($favorites)): ?>
        <div class="fav-section">
            <div class="fav-section-header"><?= icon('star-filled', 11) ?> Favorites</div>
            <div class="fav-list">
                <?php foreach ($favorites as $fav): ?>
                <a href="?db=<?= urlencode($fav['db']) ?>&table=<?= urlencode($fav['table']) ?>&tab=browse"
                   class="fav-item <?= ($fav['db'] === $currentDb && $fav['table'] === $currentTable) ? 'active' : '' ?>">
                    <?= icon('star-filled', 11, 'fav-item-star') ?>
                    <span class="fav-item-table"><?= h($fav['table']) ?></span>
                    <span class="fav-item-db"><?= h($fav['db']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($databases as $dbName): ?>
        <?php
            $isExpanded = ($dbName === $currentDb);
            $tables = [];
            if ($isExpanded) {
                try {
                    $tables = $dbInstance->getTables($dbName);
                } catch (Exception $e) {
                    $tables = [];
                }
            }
        ?>
        <div class="db-group <?= $isExpanded ? 'db-group-expanded' : '' ?>">
            <div class="db-item <?= $isExpanded ? 'active' : '' ?>">
                <?php if ($isExpanded): ?>
                <button type="button" class="db-chevron open db-toggle" title="Collapse">
                    <?= icon('chevron-down', 12) ?>
                </button>
                <?php else: ?>
                <a href="?db=<?= urlencode($dbName) ?>&tab=browse" class="db-chevron" title="Expand">
                    <?= icon('chevron-right', 12) ?>
                </a>
                <?php endif; ?>
                <a href="?db=<?= urlencode($dbName) ?>&tab=browse" class="db-link">
                    <?= icon('database', 14, 'db-icon') ?>
                    <span class="db-name"><?= h($dbName) ?></span>
                </a>
                <?php if ($isExpanded): ?>
                <span class="db-count"><?= count($tables) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($isExpanded && !empty($tables)): ?>
            <div class="table-list">
                <?php foreach ($tables as $tbl): ?>
                <?php
                    $tblName = $tbl['Name'];
                    $isFav = isset($favSet[$dbName . '.' . $tblName]);
                    try {
                        $exactCount = $dbInstance->getExactRowCount($dbName, $tblName);
                    } catch (Exception $e) {
                        $exactCount = $tbl['Rows'] ?? 0;
                    }
                ?>
                <div class="table-row <?= $tblName === $currentTable ? 'active' : '' ?>">
                    <a href="?db=<?= urlencode($dbName) ?>&table=<?= urlencode($tblName) ?>&tab=browse" class="table-item">
                        <?= icon('table', 13, 'table-icon') ?>
                        <span class="table-name"><?= h($tblName) ?></span>
                        <span class="row-count"><?= format_number($exactCount) ?></span>
                    </a>
                    <button type="button"
                            class="table-fav-btn <?= $isFav ? 'is-fav' : '' ?>"
                            data-db="<?= h($dbName) ?>"
                            data-table="<?= h($tblName) ?>"
                            title="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>">
                        <?= icon($isFav ? 'star-filled' : 'star', 12) ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
                // Views for this database
                $dbViews = [];
                if ($isExpanded) {
                    try { $dbViews = $dbInstance->getViews($dbName); } catch (Exception $e) {}
                }
            ?>
            <?php if (!empty($dbViews)): ?>
            <div class="table-list views-list">
                <div class="sidebar-section-label"><?= icon('eye', 10) ?> Views</div>
                <?php foreach ($dbViews as $v): ?>
                <div class="table-row view-row <?= $v['name'] === $currentTable ? 'active' : '' ?>">
                    <a href="?db=<?= urlencode($dbName) ?>&table=<?= urlencode($v['name']) ?>&tab=browse" class="table-item">
                        <?= icon('eye', 13, 'view-icon') ?>
                        <span class="table-name"><?= h($v['name']) ?></span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</aside>
