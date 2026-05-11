<?php
/**
 * Ledger — Database Management Tool
 */

// Single source of truth for the app version. Intentionally NOT read from
// config.php — config is per-install and never updated on upgrade, so
// keeping version here ensures the running code and the displayed version
// can never drift apart.
const LEDGER_VERSION = '1.0.0-beta';

// Error Handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo '<pre style="font-family:monospace;padding:20px;">Ledger Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
});

// First-run check
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

// Bootstrap
$config = require __DIR__ . '/config.php';
require __DIR__ . '/includes/Database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/icons.php';
require __DIR__ . '/includes/Auth.php';
require __DIR__ . '/includes/favorites.php';
require __DIR__ . '/includes/TOTP.php';
require __DIR__ . '/includes/saved_queries.php';

// Security Bootstrap
$auth = new Auth($config['security']);

// 1) Security headers (always)
$auth->sendSecurityHeaders();

// 2) HTTPS enforcement
if ($auth->shouldForceHttps()) {
    $url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: {$url}", true, 301);
    exit;
}

// 3) IP whitelist
if (!$auth->isIpAllowed()) {
    http_response_code(403);
    echo '<div style="font-family:monospace;padding:40px;text-align:center;color:#888;">Access denied. Your IP is not whitelisted.</div>';
    exit;
}

// 4) Start session (needed for auth + CSRF)
$auth->startSession();

// Theme System (needed for login page too)
$themeData = ledger_load_themes(__DIR__ . '/themes', $config['app']['default_theme']);
$themes      = $themeData['list'];
$activeTheme = $themeData['active'];

$appName    = $config['app']['name'];
$appVersion = LEDGER_VERSION;
$serverHost = $config['db']['host'];

// Authentication
if ($auth->isAuthRequired()) {
    $action = input('action');

    // Handle logout
    if ($action === 'logout') {
        $auth->logout();
        header('Location: ?');
        exit;
    }

    // Handle login POST
    $loginError = '';
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate CSRF even on login
        if (!$auth->validateCsrf()) {
            $loginError = 'Invalid security token. Please try again.';
        } else {
            $result = $auth->login(
                $_POST['username'] ?? '',
                $_POST['password'] ?? ''
            );
            if ($result === true) {
                header('Location: ?');
                exit;
            } elseif ($result === '2fa_required') {
                // Fall through — will show 2FA form below
            } else {
                $loginError = $result;
            }
        }
    }

    // Handle 2FA verification POST
    if ($action === 'verify_2fa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->validateCsrf()) {
            $loginError = 'Invalid security token. Please try again.';
        } else {
            $result = $auth->verify2fa($_POST['totp_code'] ?? '');
            if ($result === true) {
                header('Location: ?');
                exit;
            } else {
                $loginError = $result;
            }
        }
    }

    // If 2FA is pending, show 2FA form
    if ($auth->is2faPending()) {
        include __DIR__ . '/templates/login_2fa.php';
        exit;
    }

    // If not logged in, show login page
    if (!$auth->isLoggedIn()) {
        include __DIR__ . '/templates/login.php';
        exit;
    }
}

// Database Connection
$dbInstance = Database::getInstance($config['db']);

try {
    $dbInstance->connect();
    $databases = $dbInstance->getDatabases();
    $databases = $auth->filterDatabases($databases);
    $serverVersion = $dbInstance->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    $connected = true;

    // Quick stats for header
    try {
        $pdo = $dbInstance->getPdo();
        $uptime = (int)$pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch()['Value'] ?? 0;
        $charset = $pdo->query("SELECT @@character_set_server")->fetchColumn() ?: '?';
        $totalQueries = (int)($pdo->query("SHOW STATUS LIKE 'Queries'")->fetch()['Value'] ?? 0);
    } catch (Exception $e) {
        $uptime = 0;
        $charset = '?';
        $totalQueries = 0;
    }
} catch (PDOException $e) {
    $connected = false;
    $databases = [];
    $serverVersion = '?';
    $connectionError = $e->getMessage();
    $uptime = 0;
    $charset = '?';
    $totalQueries = 0;
}

// Routing
$currentDb    = input('db') ?: null;
$currentTable = input('table') ?: null;
$activeTab    = input('tab', 'browse');
$action       = input('action');

// Reset tabs that require context
if (!$currentTable && in_array($activeTab, ['structure', 'info', 'operations'])) {
    $activeTab = 'browse';
}

// Block access to hidden databases
if ($currentDb && $auth->isDatabaseHidden($currentDb)) {
    $currentDb = null;
    $currentTable = null;
}

// Handle Actions (exports, drop, truncate)
if ($action && $connected) {

    // Read-only guard for destructive actions
    $writeActions = ['truncate', 'drop'];
    if ($auth->isReadOnly() && in_array($action, $writeActions)) {
        $actionError = 'Write operations are disabled in read-only mode.';
        $action = null;
    }

    // Export guard
    if (in_array($action, ['export_sql', 'export_csv', 'export_db']) && empty($config['app']['enable_export'])) {
        $actionError = 'Export is disabled.';
        $action = null;
    }

    if ($action) {
        switch ($action) {
            case 'export_sql':
                if ($currentDb && $currentTable) {
                    $mode = $_GET['mode'] ?? 'full';
                    if (!in_array($mode, ['full', 'structure', 'data'], true)) $mode = 'full';
                    $suffix = $mode === 'structure' ? '.structure' : ($mode === 'data' ? '.data' : '');
                    header('Content-Type: application/sql');
                    header("Content-Disposition: attachment; filename=\"{$currentTable}{$suffix}.sql\"");

                    $pdo = $dbInstance->connect($currentDb);
                    $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
                    $rowCount = $pdo->query("SELECT COUNT(*) FROM `{$currentTable}`")->fetchColumn();

                    $modeLabel = $mode === 'structure' ? 'Structure only' : ($mode === 'data' ? 'Data only' : 'Structure + data');
                    $line = str_repeat('-', 60);
                    echo "-- {$line}\n";
                    echo "--\n";
                    echo "--  Ledger · Table Export\n";
                    echo "--\n";
                    echo "--  Database:     {$currentDb}\n";
                    echo "--  Table:        {$currentTable}\n";
                    echo "--  Mode:         {$modeLabel}\n";
                    echo "--  Rows:         " . number_format((int)$rowCount) . "\n";
                    echo "--  Server:       {$serverVersion}\n";
                    echo "--  Exported by:  " . (isset($auth) ? ($auth->getUsername() ?: 'anonymous') : 'anonymous') . "\n";
                    echo "--  Date:         " . date('Y-m-d H:i:s T') . "\n";
                    echo "--  Generator:    Ledger v" . (LEDGER_VERSION) . "\n";
                    echo "--\n";
                    echo "-- {$line}\n\n";

                    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
                    if ($mode !== 'data') {
                        echo "DROP TABLE IF EXISTS `{$currentTable}`;\n\n";
                    }
                    echo $dbInstance->exportTable($currentDb, $currentTable, $mode);
                    echo "\nSET FOREIGN_KEY_CHECKS = 1;\n";
                    exit;
                }
                break;

            case 'export_csv':
                if ($currentDb && $currentTable) {
                    $csv = $dbInstance->exportTableCsv($currentDb, $currentTable);
                    header('Content-Type: text/csv');
                    header("Content-Disposition: attachment; filename=\"{$currentTable}.csv\"");
                    echo $csv;
                    exit;
                }
                break;

            case 'export_db':
                if ($currentDb) {
                    $mode = $_GET['mode'] ?? 'full';
                    if (!in_array($mode, ['full', 'structure', 'data'], true)) $mode = 'full';
                    $style = $_GET['style'] ?? 'single';
                    if (!in_array($style, ['single', 'phpmyadmin'], true)) $style = 'single';

                    $suffix = $mode === 'structure' ? '.structure' : ($mode === 'data' ? '.data' : '');
                    header('Content-Type: application/sql');
                    header("Content-Disposition: attachment; filename=\"{$currentDb}{$suffix}.sql\"");
                    $tables = $dbInstance->getTables($currentDb);
                    $tableCount = count($tables);
                    $totalRows = 0;
                    foreach ($tables as $tbl) {
                        $totalRows += (int)($tbl['Rows'] ?? 0);
                    }

                    // Server info
                    $pdo = $dbInstance->connect($currentDb);
                    $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
                    $serverInfo = $pdo->getAttribute(\PDO::ATTR_SERVER_INFO) ?? '';
                    $charset = $pdo->query("SELECT @@character_set_database")->fetchColumn() ?: 'utf8mb4';
                    $collation = $pdo->query("SELECT @@collation_database")->fetchColumn() ?: '';

                    $modeLabel  = $mode  === 'structure' ? 'Structure only' : ($mode === 'data' ? 'Data only' : 'Structure + data');
                    $styleLabel = $style === 'phpmyadmin' ? 'phpMyAdmin-compatible (4-pass)' : 'Single-statement';
                    $line = str_repeat('-', 60);
                    echo "-- {$line}\n";
                    echo "--\n";
                    echo "--  Ledger · Database Export\n";
                    echo "--\n";
                    echo "--  Database:     {$currentDb}\n";
                    echo "--  Mode:         {$modeLabel}\n";
                    echo "--  Style:        {$styleLabel}\n";
                    echo "--  Server:       {$serverVersion}\n";
                    echo "--  Charset:      {$charset}" . ($collation ? " / {$collation}" : "") . "\n";
                    echo "--  Tables:       {$tableCount}\n";
                    echo "--  Total rows:   " . number_format($totalRows) . "\n";
                    echo "--  Exported by:  " . ($auth->getUsername() ?: 'anonymous') . "\n";
                    echo "--  Date:         " . date('Y-m-d H:i:s T') . "\n";
                    echo "--  Generator:    Ledger v" . (LEDGER_VERSION) . "\n";
                    echo "--\n";
                    echo "-- {$line}\n\n";

                    echo "SET NAMES utf8mb4;\n";
                    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
                    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
                    if ($style === 'phpmyadmin') {
                        echo "START TRANSACTION;\n";
                        echo "SET time_zone = \"+00:00\";\n";
                    }
                    echo "\n";

                    if ($mode !== 'data') {
                        echo "CREATE DATABASE IF NOT EXISTS `{$currentDb}` /*!40100 DEFAULT CHARACTER SET {$charset} */;\nUSE `{$currentDb}`;\n\n";
                    } else {
                        echo "USE `{$currentDb}`;\n\n";
                    }

                    if ($style === 'single') {
                        // ─── Original single-statement style ────────────────
                        $sectionHeader = $mode === 'structure' ? 'Table structure' : ($mode === 'data' ? 'Table data' : 'Table structure and data');
                        echo "-- {$line}\n";
                        echo "--  {$sectionHeader}\n";
                        echo "-- {$line}\n\n";

                        foreach ($tables as $tbl) {
                            $tblName = $tbl['Name'];
                            $tblRows = number_format((int)($tbl['Rows'] ?? 0));
                            echo "-- ---\n";
                            echo "-- Table: {$tblName} ({$tblRows} rows)\n";
                            echo "-- ---\n\n";
                            if ($mode !== 'data') {
                                echo "DROP TABLE IF EXISTS `{$tblName}`;\n\n";
                            }
                            echo $dbInstance->exportTable($currentDb, $tblName, $mode);
                            echo "\n\n";
                        }
                    } else {
                        // ─── phpMyAdmin 4-pass style ────────────────────────
                        // Pre-compute split creates once per table
                        $tableData = [];
                        foreach ($tables as $tbl) {
                            $tblName = $tbl['Name'];
                            $create = $dbInstance->getCreateStatement($currentDb, $tblName);
                            $parts = $dbInstance->splitCreateStatement($create);
                            $tableData[$tblName] = [
                                'parts' => $parts,
                                'rows'  => (int)($tbl['Rows'] ?? 0),
                            ];
                        }

                        // ── Pass 1: CREATE TABLE (columns only), always if mode !== 'data'
                        if ($mode !== 'data') {
                            foreach ($tables as $tbl) {
                                $tblName = $tbl['Name'];
                                echo "-- --------------------------------------------------------\n\n";
                                echo "--\n-- Table structure for table `{$tblName}`\n--\n\n";
                                echo "DROP TABLE IF EXISTS `{$tblName}`;\n";
                                echo $tableData[$tblName]['parts']['create'] . ";\n\n";
                            }
                        }

                        // ── Pass 2: INSERT data, if mode !== 'structure'
                        if ($mode !== 'structure') {
                            foreach ($tables as $tbl) {
                                $tblName = $tbl['Name'];
                                // Use data-only export, skipping CREATE
                                $dataOnly = $dbInstance->exportTable($currentDb, $tblName, 'data');
                                if (trim($dataOnly) === '') continue;
                                echo "--\n-- Dumping data for table `{$tblName}`\n--\n\n";
                                echo $dataOnly;
                                echo "\n";
                            }
                        }

                        // ── Pass 3: Indexes (PRIMARY KEY + KEY/UNIQUE/FULLTEXT), if mode !== 'data'
                        if ($mode !== 'data') {
                            echo "--\n-- Indexes for dumped tables\n--\n\n";
                            foreach ($tables as $tbl) {
                                $tblName = $tbl['Name'];
                                $parts = $tableData[$tblName]['parts'];
                                if ($parts['primary_key'] === '' && empty($parts['keys'])) continue;
                                echo "--\n-- Indexes for table `{$tblName}`\n--\n";
                                echo "ALTER TABLE `{$tblName}`\n";
                                $clauses = [];
                                if ($parts['primary_key'] !== '') $clauses[] = '  ' . $parts['primary_key'];
                                foreach ($parts['keys'] as $k) $clauses[] = '  ' . $k;
                                echo implode(",\n", $clauses) . ";\n\n";
                            }
                        }

                        // ── Pass 4: AUTO_INCREMENT, if mode !== 'data'
                        if ($mode !== 'data') {
                            $hasAi = false;
                            foreach ($tableData as $tn => $td) {
                                if ($td['parts']['auto_increment'] !== null) { $hasAi = true; break; }
                            }
                            if ($hasAi) {
                                echo "--\n-- AUTO_INCREMENT for dumped tables\n--\n\n";
                                foreach ($tables as $tbl) {
                                    $tblName = $tbl['Name'];
                                    $ai = $tableData[$tblName]['parts']['auto_increment'];
                                    if ($ai === null) continue;
                                    $colName = $ai['col'];
                                    $colDef  = $dbInstance->getColumnDefinition($currentDb, $tblName, $colName);
                                    // Strip AUTO_INCREMENT from the base def; we'll add it back
                                    $colDef = preg_replace('/\s*AUTO_INCREMENT/i', '', $colDef);
                                    $aiVal  = $dbInstance->getAutoIncrementValue($currentDb, $tblName);
                                    echo "--\n-- AUTO_INCREMENT for table `{$tblName}`\n--\n";
                                    echo "ALTER TABLE `{$tblName}`\n";
                                    echo "  MODIFY `{$colName}` {$colDef} AUTO_INCREMENT";
                                    if ($aiVal !== null && $aiVal > 1) echo ", AUTO_INCREMENT={$aiVal}";
                                    echo ";\n\n";
                                }
                            }
                        }

                        // ── Pass 5: Foreign keys, if mode !== 'data'
                        if ($mode !== 'data') {
                            $hasFk = false;
                            foreach ($tableData as $td) {
                                if (!empty($td['parts']['foreign_keys'])) { $hasFk = true; break; }
                            }
                            if ($hasFk) {
                                echo "--\n-- Constraints for dumped tables\n--\n\n";
                                foreach ($tables as $tbl) {
                                    $tblName = $tbl['Name'];
                                    $fks = $tableData[$tblName]['parts']['foreign_keys'];
                                    if (empty($fks)) continue;
                                    echo "--\n-- Constraints for table `{$tblName}`\n--\n";
                                    echo "ALTER TABLE `{$tblName}`\n";
                                    $clauses = array_map(fn($c) => '  ' . $c, $fks);
                                    echo implode(",\n", $clauses) . ";\n\n";
                                }
                            }
                        }

                        if ($style === 'phpmyadmin') {
                            echo "COMMIT;\n\n";
                        }
                    }

                    echo "SET FOREIGN_KEY_CHECKS = 1;\n\n";
                    echo "-- Export complete.\n";

                    $auth->logActivity("Exported database: {$currentDb} ({$modeLabel}, {$styleLabel})");
                    exit;
                }
                break;

            case 'truncate':
                if ($currentDb && $currentTable) {
                    try {
                        $dbInstance->truncateTable($currentDb, $currentTable);
                        $auth->logActivity("Truncated table: {$currentDb}.{$currentTable}");
                        header("Location: ?db=" . urlencode($currentDb) . "&table=" . urlencode($currentTable) . "&tab=browse&msg=truncated");
                        exit;
                    } catch (Exception $e) {
                        $actionError = $e->getMessage();
                    }
                }
                break;

            case 'drop':
                if ($currentDb && $currentTable) {
                    try {
                        $dbInstance->dropTable($currentDb, $currentTable);
                        $auth->logActivity("Dropped table: {$currentDb}.{$currentTable}");
                        header("Location: ?db=" . urlencode($currentDb) . "&tab=browse&msg=dropped");
                        exit;
                    } catch (Exception $e) {
                        $actionError = $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Determine Content Template
if ($activeTab === 'settings') {
    // Process settings POST BEFORE layout renders so we can redirect
    // and so the rest of this request uses the fresh config.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_settings_action'] ?? '') === 'save') {
        require_once __DIR__ . '/templates/settings_save.php';
        $saveResult = ledger_save_settings($config, $auth);
        if ($saveResult['success']) {
            $_SESSION['settings_msg'] = 'Settings saved successfully.';
            header('Location: ?tab=settings');
            exit;
        }
        // On error, fall through and render settings.php which picks up $settingsErr
        $settingsErr = $saveResult['error'] ?? 'Unknown error.';
        $config = $saveResult['config'] ?? $config; // show what they tried to save
    }
    $contentTemplate = __DIR__ . '/templates/settings.php';
} elseif (!$connected) {
    $contentTemplate = __DIR__ . '/templates/connection_error.php';
} elseif ($activeTab === 'server') {
    $contentTemplate = __DIR__ . '/templates/server_info.php';
} elseif ($activeTab === 'processes') {
    $contentTemplate = __DIR__ . '/templates/processes.php';
} elseif ($activeTab === 'sql') {
    $contentTemplate = __DIR__ . '/templates/sql.php';
} elseif ($activeTab === 'structure' && $currentTable) {
    $contentTemplate = __DIR__ . '/templates/structure.php';
} elseif ($activeTab === 'operations' && $currentTable) {
    $contentTemplate = __DIR__ . '/templates/operations.php';
} elseif ($activeTab === 'info' && $currentTable) {
    $contentTemplate = __DIR__ . '/templates/info.php';
} elseif ($activeTab === 'export') {
    $contentTemplate = __DIR__ . '/templates/export.php';
} elseif ($activeTab === 'import') {
    $contentTemplate = __DIR__ . '/templates/import.php';
} elseif ($activeTab === 'search') {
    $contentTemplate = __DIR__ . '/templates/search.php';
} elseif ($activeTab === 'er') {
    $contentTemplate = __DIR__ . '/templates/er.php';
} else {
    $contentTemplate = __DIR__ . '/templates/browse.php';
}

// Render Layout
include __DIR__ . '/templates/layout.php';
