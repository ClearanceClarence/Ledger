<?php
/**
 * Ledger — AJAX Endpoint
 * Secured with auth, CSRF, read-only mode, and query logging.
 */

header('Content-Type: application/json');
// Prevent browsers from caching AJAX responses — schema queries must always
// reflect current state. Without this, browsers cache er_data, get_tables,
// processlist, etc. and display stale schema after modifications.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!file_exists(__DIR__ . '/config.php')) {
    echo json_encode(['error' => 'Not installed. Please run the installer.']);
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/includes/Database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/icons.php';
require __DIR__ . '/includes/Auth.php';
require __DIR__ . '/includes/favorites.php';

// Security checks
$auth = new Auth($config['security']);
$auth->startSession();

// Check auth
if ($auth->isAuthRequired() && !$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated. Please log in.']);
    exit;
}

// Check IP whitelist
if (!$auth->isIpAllowed()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// CSRF check on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->csrfEnabled() && !$auth->validateCsrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token. Please reload the page.']);
        exit;
    }
}

// Database connection
$action = $_REQUEST['action'] ?? '';
$db = $_REQUEST['db'] ?? '';

// Block hidden databases
if ($db && $auth->isDatabaseHidden($db)) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

try {
    $dbInstance = Database::getInstance($config['db']);
    $dbInstance->connect();
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Route actions
switch ($action) {

    // ─── Streaming SQL import ────────────────────────────────────────────
    // Sends NDJSON (one JSON object per line) so the client can render
    // a live progress bar. Pre-counts statements once for the denominator,
    // then streams progress every 250 statements as the import runs.
    case 'import_stream':
        // Override the default JSON headers — we're streaming NDJSON.
        // Disable any output buffering so each progress line reaches the
        // browser immediately rather than being held until script end.
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no'); // disable nginx buffering if behind it
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);

        $emit = function (array $data) {
            echo json_encode($data) . "\n";
            @ob_flush();
            @flush();
        };

        // Read-only guard
        if ($auth->isReadOnly()) {
            $emit(['phase' => 'error', 'error' => 'Import is disabled in read-only mode.']);
            exit;
        }

        // File upload validation
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
            $emit(['phase' => 'error', 'error' => $uploadErrors[$errCode] ?? 'Upload failed (code ' . $errCode . ').']);
            exit;
        }

        $filePath = $_FILES['import_file']['tmp_name'];
        $fileName = $_FILES['import_file']['name'];
        $fileSize = $_FILES['import_file']['size'];

        // Target DB
        $sqlTarget = $_POST['sql_target'] ?? 'existing';
        $targetDb = null;
        if ($sqlTarget === 'existing') {
            $targetDb = $_POST['target_db'] ?? $db ?? '';
            if (!$targetDb) {
                $emit(['phase' => 'error', 'error' => 'No target database selected.']);
                exit;
            }
            if ($auth->isDatabaseHidden($targetDb)) {
                $emit(['phase' => 'error', 'error' => 'Access denied.']);
                exit;
            }
        }

        // Phase 1: pre-count statements for the progress denominator.
        // Wrapped in try in case the file disappears or read errors out.
        $emit(['phase' => 'counting', 'file' => $fileName, 'size' => $fileSize]);
        try {
            $totalEstimate = $dbInstance->countStatementsInFile($filePath);
        } catch (Exception $e) {
            $emit(['phase' => 'error', 'error' => 'Could not pre-scan file: ' . $e->getMessage()]);
            exit;
        }
        $emit(['phase' => 'counted', 'estimate' => $totalEstimate]);

        // Phase 2: run the import, streaming progress every 250 statements
        // Fast mode is on by default per UI; user can opt out via the checkbox.
        $fast = !isset($_POST['fast']) || $_POST['fast'] !== '0';
        $startTime = microtime(true);
        $lastEmit = $startTime;
        try {
            $aggregate = $dbInstance->executeSqlDumpFromFile(
                $targetDb,
                $filePath,
                function (array $running) use ($emit, $startTime, &$lastEmit, $totalEstimate) {
                    // Rate-limit emissions to at most ~10/sec so we don't
                    // saturate the network on tiny dumps that finish fast.
                    $now = microtime(true);
                    if (($now - $lastEmit) < 0.1) return;
                    $lastEmit = $now;

                    $emit([
                        'phase'    => 'progress',
                        'total'    => $running['total'],
                        'success'  => $running['success'],
                        'errors'   => $running['errors'],
                        'rows'     => $running['rows'],
                        'estimate' => $totalEstimate,
                        'elapsed'  => $now - $startTime,
                    ]);
                },
                250,    // progressEvery
                $fast
            );
        } catch (RuntimeException $e) {
            $emit(['phase' => 'error', 'error' => $e->getMessage()]);
            exit;
        }

        // Phase 3: done
        $elapsed = microtime(true) - $startTime;
        $label = $targetDb ? "into {$targetDb}" : "(new database from file)";
        $auth->logActivity("SQL import: {$fileName} ({$fileSize} bytes) {$label} — {$aggregate['success']} OK, {$aggregate['errors']} errors");

        $emit([
            'phase'      => 'done',
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
        ]);
        exit;

    // Autocomplete data (GET, read-only safe)
    case 'autocomplete':
        $result = [
            'databases' => [],
            'tables'    => [],
            'columns'   => [],
        ];

        $result['databases'] = $auth->filterDatabases($dbInstance->getDatabases());

        if ($db) {
            try {
                $tables = $dbInstance->getTables($db);
                foreach ($tables as $tbl) {
                    $tblName = $tbl['Name'];
                    $result['tables'][] = [
                        'name'   => $tblName,
                        'rows'   => (int) ($tbl['Rows'] ?? 0),
                        'engine' => $tbl['Engine'] ?? '',
                    ];

                    try {
                        $cols = $dbInstance->getColumns($db, $tblName);
                        foreach ($cols as $col) {
                            $result['columns'][] = [
                                'name'    => $col['Field'],
                                'table'   => $tblName,
                                'type'    => $col['Type'],
                                'key'     => $col['Key'] ?? '',
                                'null'    => $col['Null'] ?? '',
                            ];
                        }
                    } catch (Exception $e) {}
                }
            } catch (Exception $e) {}
        }

        echo json_encode($result);
        break;

    // Update a single cell (POST, write)
    case 'update_cell':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table  = $_POST['table'] ?? '';
        $column = $_POST['column'] ?? '';
        $value  = $_POST['value'] ?? null;
        $isNull = ($_POST['is_null'] ?? '0') === '1';
        $pkCol  = $_POST['pk_col'] ?? '';
        $pkVal  = $_POST['pk_val'] ?? '';

        if (!$db || !$table || !$column || !$pkCol) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $colSafe = '`' . str_replace('`', '``', $column) . '`';
            $tblSafe = '`' . str_replace('`', '``', $table) . '`';
            $pkSafe  = '`' . str_replace('`', '``', $pkCol) . '`';

            $start = microtime(true);

            if ($isNull) {
                $sql = "UPDATE {$tblSafe} SET {$colSafe} = NULL WHERE {$pkSafe} = :pk LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['pk' => $pkVal]);
            } else {
                $sql = "UPDATE {$tblSafe} SET {$colSafe} = :val WHERE {$pkSafe} = :pk LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['val' => $value, 'pk' => $pkVal]);
            }

            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, "UPDATE {$table} SET {$column} = " . ($isNull ? 'NULL' : "'{$value}'") . " WHERE {$pkCol} = {$pkVal}", $elapsed);

            echo json_encode([
                'success'  => true,
                'affected' => $stmt->rowCount(),
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Delete a row (POST, write)
    case 'delete_row':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table = $_POST['table'] ?? '';
        $pkCol = $_POST['pk_col'] ?? '';
        $pkVal = $_POST['pk_val'] ?? '';

        if (!$db || !$table || !$pkCol) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $tblSafe = '`' . str_replace('`', '``', $table) . '`';
            $pkSafe  = '`' . str_replace('`', '``', $pkCol) . '`';

            $start = microtime(true);
            $stmt = $pdo->prepare("DELETE FROM {$tblSafe} WHERE {$pkSafe} = :pk LIMIT 1");
            $stmt->execute(['pk' => $pkVal]);
            $elapsed = microtime(true) - $start;

            $auth->logQuery($db, "DELETE FROM {$table} WHERE {$pkCol} = {$pkVal}", $elapsed);

            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Delete multiple rows (POST, write)
    case 'bulk_delete':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table = $_POST['table'] ?? '';
        $pkCol = $_POST['pk_col'] ?? '';
        $pkVals = json_decode($_POST['pk_vals'] ?? '[]', true);

        if (!$db || !$table || !$pkCol || !is_array($pkVals) || empty($pkVals)) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $tblSafe = '`' . str_replace('`', '``', $table) . '`';
            $pkSafe  = '`' . str_replace('`', '``', $pkCol) . '`';

            $placeholders = implode(',', array_fill(0, count($pkVals), '?'));
            $start = microtime(true);
            $stmt = $pdo->prepare("DELETE FROM {$tblSafe} WHERE {$pkSafe} IN ({$placeholders})");
            $stmt->execute(array_values($pkVals));
            $elapsed = microtime(true) - $start;

            $auth->logQuery($db, "DELETE FROM {$table} WHERE {$pkCol} IN (" . implode(',', $pkVals) . ")", $elapsed);

            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Alter a column (POST, write)
    case 'alter_column':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table    = $_POST['table'] ?? '';
        $origName = $_POST['original_name'] ?? '';
        $newName  = $_POST['new_name'] ?? '';
        $newType  = $_POST['new_type'] ?? '';
        $nullable = ($_POST['nullable'] ?? '0') === '1';
        $defNull  = ($_POST['default_null'] ?? '0') === '1';
        $defVal   = $_POST['default_value'] ?? '';
        $extra    = $_POST['extra'] ?? '';
        $comment  = $_POST['comment'] ?? '';

        if (!$db || !$table || !$origName || !$newName || !$newType) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $esc = function($s) { return '`' . str_replace('`', '``', $s) . '`'; };

            $sql = "ALTER TABLE {$esc($table)} CHANGE {$esc($origName)} {$esc($newName)} {$newType}";
            $sql .= $nullable ? ' NULL' : ' NOT NULL';
            if ($defNull) {
                $sql .= ' DEFAULT NULL';
            } elseif ($defVal !== '') {
                if (strtoupper($defVal) === 'CURRENT_TIMESTAMP') {
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                } else {
                    $sql .= ' DEFAULT ' . $pdo->quote($defVal);
                }
            }
            if ($extra) {
                $sql .= ' ' . $extra;
            }
            if ($comment !== '') {
                $sql .= ' COMMENT ' . $pdo->quote($comment);
            }

            $start = microtime(true);
            $pdo->exec($sql);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, $sql, $elapsed);

            echo json_encode(['success' => true, 'sql' => $sql]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop a column (POST, write)
    case 'drop_column':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table  = $_POST['table'] ?? '';
        $column = $_POST['column'] ?? '';

        if (!$db || !$table || !$column) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $esc = function($s) { return '`' . str_replace('`', '``', $s) . '`'; };
            $sql = "ALTER TABLE {$esc($table)} DROP COLUMN {$esc($column)}";

            $start = microtime(true);
            $pdo->exec($sql);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, $sql, $elapsed);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Add a column (POST, write)
    case 'add_column':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table    = $_POST['table'] ?? '';
        $name     = $_POST['name'] ?? '';
        $type     = $_POST['type'] ?? '';
        $nullable = ($_POST['nullable'] ?? '0') === '1';
        $defNull  = ($_POST['default_null'] ?? '0') === '1';
        $defVal   = $_POST['default_value'] ?? '';
        $comment  = $_POST['comment'] ?? '';
        $after    = $_POST['after'] ?? '';

        if (!$db || !$table || !$name || !$type) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $esc = function($s) { return '`' . str_replace('`', '``', $s) . '`'; };

            $sql = "ALTER TABLE {$esc($table)} ADD COLUMN {$esc($name)} {$type}";
            $sql .= $nullable ? ' NULL' : ' NOT NULL';
            if ($defNull) {
                $sql .= ' DEFAULT NULL';
            } elseif ($defVal !== '') {
                if (strtoupper($defVal) === 'CURRENT_TIMESTAMP') {
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                } else {
                    $sql .= ' DEFAULT ' . $pdo->quote($defVal);
                }
            }
            if ($comment !== '') {
                $sql .= ' COMMENT ' . $pdo->quote($comment);
            }
            if ($after === 'FIRST') {
                $sql .= ' FIRST';
            } elseif ($after !== '') {
                $sql .= " AFTER {$esc($after)}";
            }

            $start = microtime(true);
            $pdo->exec($sql);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, $sql, $elapsed);

            echo json_encode(['success' => true, 'sql' => $sql]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Add an index (POST, write)
    case 'add_index':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table    = $_POST['table'] ?? '';
        $type     = $_POST['type'] ?? '';
        $name     = $_POST['name'] ?? '';
        $colsRaw  = $_POST['columns'] ?? '';

        if (!$db || !$table || !$type || !$colsRaw) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        // Columns arrive as comma-separated "col1,col2(10)"
        $columns = array_values(array_filter(array_map('trim', explode(',', $colsRaw))));

        try {
            $start = microtime(true);
            $dbInstance->addIndex($db, $table, $type, $columns, $name);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, "ALTER TABLE `{$table}` ADD {$type} INDEX (" . implode(', ', $columns) . ")", $elapsed);
            echo json_encode(['success' => true]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop an index (POST, write)
    case 'drop_index':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table = $_POST['table'] ?? '';
        $index = $_POST['index'] ?? '';

        if (!$db || !$table || !$index) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $start = microtime(true);
            $dbInstance->dropIndex($db, $table, $index);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, "ALTER TABLE `{$table}` DROP INDEX `{$index}`", $elapsed);
            echo json_encode(['success' => true]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Set AUTO_INCREMENT (POST, write)
    case 'set_auto_increment':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table = $_POST['table'] ?? '';
        $value = (int)($_POST['value'] ?? 0);

        if (!$db || !$table) {
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $esc = function($s) { return '`' . str_replace('`', '``', $s) . '`'; };

            if ($value === 0) {
                // Reset: find max PK value + 1
                // Get the auto_increment column name
                $stmt = $pdo->query("SHOW COLUMNS FROM {$esc($table)} WHERE Extra LIKE '%auto_increment%'");
                $aiCol = $stmt->fetch();
                if (!$aiCol) {
                    echo json_encode(['error' => 'No AUTO_INCREMENT column found']);
                    break;
                }
                $colName = $aiCol['Field'];
                $maxStmt = $pdo->query("SELECT COALESCE(MAX({$esc($colName)}), 0) + 1 AS next_val FROM {$esc($table)}");
                $value = (int)$maxStmt->fetchColumn();
            }

            $sql = "ALTER TABLE {$esc($table)} AUTO_INCREMENT = {$value}";
            $start = microtime(true);
            $pdo->exec($sql);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($db, $sql, $elapsed);

            echo json_encode(['success' => true, 'new_value' => $value]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ER diagram data (GET)
    case 'er_data':
        if (!$db) {
            echo json_encode(['error' => 'No database selected.']);
            break;
        }
        try {
            $tables = $dbInstance->getTables($db);
            $erTables = [];
            $erRelations = [];

            foreach ($tables as $t) {
                $name = $t['Name'];
                $cols = $dbInstance->getColumns($db, $name);
                $columns = [];
                foreach ($cols as $c) {
                    $columns[] = [
                        'name'  => $c['Field'],
                        'type'  => $c['Type'],
                        'key'   => $c['Key'],
                        'null'  => $c['Null'],
                        'extra' => $c['Extra'],
                    ];
                }
                $erTables[] = [
                    'name'    => $name,
                    'columns' => $columns,
                    'rows'    => (int)($t['Rows'] ?? 0),
                    'engine'  => $t['Engine'] ?? '',
                ];

                // FK relationships
                try {
                    $fks = $dbInstance->getForeignKeys($db, $name);
                    foreach ($fks as $fk) {
                        $erRelations[] = [
                            'from_table'  => $name,
                            'from_col'    => $fk['COLUMN_NAME'],
                            'to_table'    => $fk['REFERENCED_TABLE_NAME'],
                            'to_col'      => $fk['REFERENCED_COLUMN_NAME'],
                            'constraint'  => $fk['CONSTRAINT_NAME'] ?? '',
                            'on_delete'   => $fk['DELETE_RULE'] ?? '',
                            'on_update'   => $fk['UPDATE_RULE'] ?? '',
                        ];
                    }
                } catch (Exception $e) {}
            }

            echo json_encode(['tables' => $erTables, 'relations' => $erRelations]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Save ER diagram layout (POST)
    case 'er_save_layout':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        $layoutData = $_POST['layout'] ?? '';
        if (!$layoutData) { echo json_encode(['error' => 'No layout data.']); break; }
        $erDir = __DIR__ . '/logs/er';
        if (!is_dir($erDir)) mkdir($erDir, 0755, true);
        $safeDb = preg_replace('/[^a-zA-Z0-9_-]/', '_', $db);
        $file = $erDir . '/' . $safeDb . '.json';
        $written = file_put_contents($file, $layoutData);
        if ($written === false) { echo json_encode(['error' => 'Could not write layout file.']); break; }
        echo json_encode(['success' => true]);
        break;

    // Load ER diagram layout (GET)
    case 'er_load_layout':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        $safeDb = preg_replace('/[^a-zA-Z0-9_-]/', '_', $db);
        $file = __DIR__ . '/logs/er/' . $safeDb . '.json';
        if (file_exists($file)) {
            $data = file_get_contents($file);
            header('Content-Type: application/json');
            echo $data;
        } else {
            echo json_encode(['empty' => true]);
        }
        break;

    // Delete saved ER diagram layout (POST, write)
    case 'er_reset_layout':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        $safeDb = preg_replace('/[^a-zA-Z0-9_-]/', '_', $db);
        $file = __DIR__ . '/logs/er/' . $safeDb . '.json';
        if (file_exists($file)) {
            if (!@unlink($file)) {
                echo json_encode(['error' => 'Could not delete layout file.']);
                break;
            }
        }
        echo json_encode(['success' => true]);
        break;

    // Rename a table (POST, write)
    case 'rename_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $oldName = trim($_POST['old_name'] ?? '');
        $newName = trim($_POST['new_name'] ?? '');
        if (!$db || !$oldName || !$newName) {
            echo json_encode(['error' => 'Database, old name, and new name are required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $newName)) {
            echo json_encode(['error' => 'Table name can only contain letters, numbers, and underscores.']);
            break;
        }
        try {
            $dbInstance->renameTable($db, $oldName, $newName);
            $auth->logActivity("Renamed table: {$db}.{$oldName} → {$newName}");
            echo json_encode(['success' => true, 'new_name' => $newName]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Copy a table (POST, write)
    case 'copy_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $source = trim($_POST['source'] ?? '');
        $dest = trim($_POST['destination'] ?? '');
        $destDb = trim($_POST['dest_db'] ?? '') ?: null;
        $withData = isset($_POST['with_data']);
        if (!$db || !$source || !$dest) {
            echo json_encode(['error' => 'Database, source table, and destination name are required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dest)) {
            echo json_encode(['error' => 'Table name can only contain letters, numbers, and underscores.']);
            break;
        }
        try {
            $dbInstance->copyTable($db, $source, $dest, $withData, $destDb);
            $label = $withData ? 'with data' : 'structure only';
            $target = $destDb ? "{$destDb}.{$dest}" : "{$db}.{$dest}";
            $auth->logActivity("Copied table: {$db}.{$source} → {$target} ({$label})");
            echo json_encode(['success' => true, 'destination' => $dest, 'dest_db' => $destDb ?: $db]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Move a table to another database (POST, write)
    case 'move_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $tableName = trim($_POST['table'] ?? '');
        $targetDb  = trim($_POST['target_db'] ?? '');
        if (!$db || !$tableName || !$targetDb) {
            echo json_encode(['error' => 'Database, table, and target database are required.']);
            break;
        }
        if ($db === $targetDb) {
            echo json_encode(['error' => 'Target database must differ from source.']);
            break;
        }
        try {
            $dbInstance->moveTableToDatabase($db, $tableName, $targetDb);
            $auth->logActivity("Moved table: {$db}.{$tableName} → {$targetDb}.{$tableName}");
            echo json_encode(['success' => true, 'target_db' => $targetDb]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Alter table options (POST, write)
    case 'alter_table_options':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $tableName = trim($_POST['table'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        $options = [];
        if (!empty($_POST['engine']))     $options['engine']     = $_POST['engine'];
        if (!empty($_POST['collation']))  $options['collation']  = $_POST['collation'];
        if (!empty($_POST['row_format'])) $options['row_format'] = $_POST['row_format'];
        if (isset($_POST['comment']))     $options['comment']    = $_POST['comment'];
        if (empty($options)) {
            echo json_encode(['error' => 'No changes to apply.']);
            break;
        }
        try {
            $dbInstance->alterTableOptions($db, $tableName, $options);
            $auth->logActivity("Altered table options: {$db}.{$tableName} (" . implode(', ', array_keys($options)) . ')');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Maintenance operations: analyze / check / repair (POST)
    case 'analyze_table':
    case 'check_table':
    case 'repair_table':
        $tableName = trim($_POST['table'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        $method = str_replace('_table', 'Table', $action); // analyzeTable / checkTable / repairTable
        try {
            $rows = $dbInstance->$method($db, $tableName);
            $msg = [];
            foreach ($rows as $row) {
                $msg[] = ($row['Msg_type'] ?? '') . ': ' . ($row['Msg_text'] ?? '');
            }
            $auth->logActivity(ucfirst(str_replace('_table', '', $action)) . " table: {$db}.{$tableName}");
            echo json_encode(['success' => true, 'result' => implode("\n", $msg)]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Truncate a table (POST, write)
    case 'truncate_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $tableName = trim($_POST['table'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        try {
            $dbInstance->truncateTable($db, $tableName);
            $auth->logActivity("Truncated table: {$db}.{$tableName}");
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop a table (POST, write)
    case 'drop_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $tableName = trim($_POST['name'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table name are required.']);
            break;
        }
        try {
            $dbInstance->dropTable($db, $tableName);
            $auth->logActivity("Dropped table: {$db}.{$tableName}");
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Search across tables (GET)
    case 'search_tables':
        $searchTerm = trim($_GET['term'] ?? '');
        if (!$db) {
            echo json_encode(['error' => 'No database selected.']);
            break;
        }
        if (strlen($searchTerm) < 1) {
            echo json_encode(['error' => 'Search term is required.']);
            break;
        }

        try {
            $maxPerTable = min(10, max(1, (int)($_GET['limit'] ?? 5)));
            $results = $dbInstance->searchAcrossTables($db, $searchTerm, $maxPerTable);
            echo json_encode($results);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Optimize table (POST, write)
    case 'optimize_table':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $tableName = trim($_POST['table'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        try {
            $result = $dbInstance->optimizeTable($db, $tableName);
            $auth->logActivity("Optimized table: {$db}.{$tableName}");
            $msg = '';
            foreach ($result as $row) {
                $msg .= ($row['Msg_type'] ?? '') . ': ' . ($row['Msg_text'] ?? '') . "\n";
            }
            echo json_encode(['success' => true, 'result' => trim($msg)]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Generic table maintenance (POST, write for all except CHECK)
    case 'maintenance':
        $operation = strtoupper(trim($_POST['operation'] ?? ''));
        $tableName = trim($_POST['table'] ?? '');
        if (!in_array($operation, ['OPTIMIZE', 'ANALYZE', 'CHECK', 'REPAIR'])) {
            echo json_encode(['error' => 'Invalid operation.']);
            break;
        }
        if ($operation !== 'CHECK' && $auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            echo json_encode(['error' => 'Invalid table name.']);
            break;
        }
        try {
            $pdo = $dbInstance->connect($db);
            $safeName = '`' . str_replace('`', '``', $tableName) . '`';
            $rows = $pdo->query("{$operation} TABLE {$safeName}")->fetchAll(PDO::FETCH_ASSOC);
            $auth->logActivity("{$operation} TABLE: {$db}.{$tableName}");
            echo json_encode(['success' => true, 'result' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // List views (GET, read-safe)
    case 'get_views':
        if (!$db) { echo json_encode(['error' => 'Database required.']); break; }
        try {
            echo json_encode(['success' => true, 'views' => $dbInstance->getViews($db)]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get view definition (GET, read-safe)
    case 'get_view_definition':
        $name = trim($_REQUEST['name'] ?? '');
        if (!$db || !$name) { echo json_encode(['error' => 'Database and view name required.']); break; }
        try {
            $def = $dbInstance->getViewDefinition($db, $name);
            echo json_encode(['success' => true, 'definition' => $def]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Create/replace view (POST, write)
    case 'create_view':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Read-only mode.']); break; }
        $name = trim($_POST['name'] ?? '');
        $definition = trim($_POST['definition'] ?? '');
        $replace = isset($_POST['replace']);
        if (!$db || !$name || !$definition) {
            echo json_encode(['error' => 'Database, name, and definition required.']);
            break;
        }
        try {
            $dbInstance->createView($db, $name, $definition, $replace);
            $auth->logActivity(($replace ? 'Replaced' : 'Created') . " view: {$db}.{$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop view (POST, write)
    case 'drop_view':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Read-only mode.']); break; }
        $name = trim($_POST['name'] ?? '');
        if (!$db || !$name) { echo json_encode(['error' => 'Database and name required.']); break; }
        try {
            $dbInstance->dropView($db, $name);
            $auth->logActivity("Dropped view: {$db}.{$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // EXPLAIN query (POST, read-safe)
    // Saved Queries: list (GET)
    case 'get_saved_queries':
        require_once __DIR__ . '/includes/saved_queries.php';
        $username = isset($auth) ? $auth->getUsername() : 'anonymous';
        $queries = ledger_saved_queries_get($username);
        // Optionally filter by database
        $filterDb = $_GET['filter_db'] ?? '';
        if ($filterDb) {
            $queries = array_values(array_filter($queries, function($q) use ($filterDb) {
                return $q['db'] === $filterDb;
            }));
        }
        echo json_encode(['queries' => $queries]);
        break;

    // Saved Queries: save (POST)
    case 'save_query':
        require_once __DIR__ . '/includes/saved_queries.php';
        $username = isset($auth) ? $auth->getUsername() : 'anonymous';
        $name = trim($_POST['name'] ?? '');
        $sql  = trim($_POST['sql'] ?? '');
        $qdb  = trim($_POST['db'] ?? $db);
        if (!$sql) { echo json_encode(['error' => 'Query is empty.']); break; }
        if (!$name) $name = 'Query ' . date('Y-m-d H:i');
        $entry = ledger_saved_queries_add($username, $name, $sql, $qdb);
        echo json_encode(['success' => true, 'query' => $entry]);
        break;

    // Saved Queries: update (POST)
    case 'update_saved_query':
        require_once __DIR__ . '/includes/saved_queries.php';
        $username = isset($auth) ? $auth->getUsername() : 'anonymous';
        $id = trim($_POST['id'] ?? '');
        if (!$id) { echo json_encode(['error' => 'No query ID.']); break; }
        $fields = [];
        if (isset($_POST['name'])) $fields['name'] = trim($_POST['name']);
        if (isset($_POST['sql']))  $fields['sql']  = trim($_POST['sql']);
        $ok = ledger_saved_queries_update($username, $id, $fields);
        echo json_encode($ok ? ['success' => true] : ['error' => 'Query not found.']);
        break;

    // Saved Queries: delete (POST)
    case 'delete_saved_query':
        require_once __DIR__ . '/includes/saved_queries.php';
        $username = isset($auth) ? $auth->getUsername() : 'anonymous';
        $id = trim($_POST['id'] ?? '');
        if (!$id) { echo json_encode(['error' => 'No query ID.']); break; }
        $ok = ledger_saved_queries_delete($username, $id);
        echo json_encode($ok ? ['success' => true] : ['error' => 'Query not found.']);
        break;

    case 'explain_query':
        $sql = trim($_POST['sql'] ?? '');
        if (!$db || !$sql) {
            echo json_encode(['error' => 'Database and query required.']);
            break;
        }
        // Strip trailing semicolon and any trailing comments
        $sql = rtrim($sql, "; \t\n\r");
        // Don't double-prefix
        if (!preg_match('/^\s*explain\b/i', $sql)) {
            $sql = 'EXPLAIN ' . $sql;
        }
        try {
            $pdo = $dbInstance->connect($db);
            $start = microtime(true);
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            $time = round((microtime(true) - $start) * 1000, 2);
            echo json_encode(['success' => true, 'rows' => $rows, 'time' => $time, 'sql' => $sql]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // List triggers for a table (GET, read-safe)
    case 'get_triggers':
        $tableName = trim($_REQUEST['table'] ?? '');
        if (!$db || !$tableName) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        try {
            echo json_encode(['success' => true, 'triggers' => $dbInstance->getTriggers($db, $tableName)]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Create a trigger (POST, write)
    case 'create_trigger':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $name   = trim($_POST['name'] ?? '');
        $timing = trim($_POST['timing'] ?? '');
        $event  = trim($_POST['event'] ?? '');
        $table  = trim($_POST['table'] ?? '');
        $body   = trim($_POST['body'] ?? '');
        if (!$db || !$name || !$timing || !$event || !$table || !$body) {
            echo json_encode(['error' => 'All fields are required.']);
            break;
        }
        try {
            $dbInstance->createTrigger($db, $name, $timing, $event, $table, $body);
            $auth->logActivity("Created trigger: {$db}.{$name} ({$timing} {$event} ON {$table})");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Replace a trigger (POST, write)
    // Drop + recreate in one call. Restores original on failure.
    case 'replace_trigger':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $origName = trim($_POST['orig_name'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $timing   = trim($_POST['timing'] ?? '');
        $event    = trim($_POST['event'] ?? '');
        $table    = trim($_POST['table'] ?? '');
        $body     = trim($_POST['body'] ?? '');
        if (!$db || !$origName || !$name || !$timing || !$event || !$table || !$body) {
            echo json_encode(['error' => 'All fields are required.']);
            break;
        }
        // Fetch the old definition for rollback
        $oldTriggers = $dbInstance->getTriggers($db, $table);
        $old = null;
        foreach ($oldTriggers as $t) {
            if ($t['name'] === $origName) { $old = $t; break; }
        }
        if (!$old) {
            echo json_encode(['error' => "Trigger '{$origName}' not found."]);
            break;
        }
        try {
            $dbInstance->dropTrigger($db, $origName);
            try {
                $dbInstance->createTrigger($db, $name, $timing, $event, $table, $body);
                $auth->logActivity("Replaced trigger: {$db}.{$origName} → {$name}");
                echo json_encode(['success' => true]);
            } catch (Exception $createErr) {
                // Restore the original
                try {
                    $dbInstance->createTrigger($db, $old['name'], $old['timing'], $old['event'], $table, $old['body']);
                    echo json_encode(['error' => 'Create failed, original restored: ' . $createErr->getMessage()]);
                } catch (Exception $restoreErr) {
                    echo json_encode(['error' => 'Create failed AND restore failed. Trigger lost: ' . $createErr->getMessage()]);
                }
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop a trigger (POST, write)
    case 'drop_trigger':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }
        $name = trim($_POST['name'] ?? '');
        if (!$db || !$name) {
            echo json_encode(['error' => 'Database and name required.']);
            break;
        }
        try {
            $dbInstance->dropTrigger($db, $name);
            $auth->logActivity("Dropped trigger: {$db}.{$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get routines (GET)
    // Get partitions (GET)
    case 'get_partitions':
        $tableName = trim($_GET['table'] ?? '');
        if (!$db || !$tableName) { echo json_encode(['error' => 'Database and table required.']); break; }
        try {
            $partitions = $dbInstance->getPartitions($db, $tableName);
            $info = $dbInstance->getPartitionInfo($db, $tableName);
            echo json_encode(['success' => true, 'partitions' => $partitions, 'info' => $info]);
        } catch (PDOException $e) { echo json_encode(['error' => $e->getMessage()]); }
        break;

    // Partition table (POST, write)
    case 'partition_table':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Read-only mode.']); break; }
        $tableName = trim($_POST['table'] ?? '');
        $sql = trim($_POST['sql'] ?? '');
        if (!$db || !$tableName || !$sql) { echo json_encode(['error' => 'All fields required.']); break; }
        try {
            $dbInstance->partitionTable($db, $tableName, $sql);
            $auth->logActivity("Partitioned table: {$db}.{$tableName}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
        break;

    // Remove partitioning (POST, write)
    case 'remove_partitioning':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Read-only mode.']); break; }
        $tableName = trim($_POST['table'] ?? '');
        if (!$db || !$tableName) { echo json_encode(['error' => 'Database and table required.']); break; }
        try {
            $dbInstance->removePartitioning($db, $tableName);
            $auth->logActivity("Removed partitioning: {$db}.{$tableName}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
        break;

    // Partition action: drop, truncate, optimize, rebuild, add (POST, write)
    case 'partition_action':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Read-only mode.']); break; }
        $tableName = trim($_POST['table'] ?? '');
        $action = strtolower(trim($_POST['op'] ?? ''));
        $partName = trim($_POST['partition'] ?? '');
        $partDef = trim($_POST['definition'] ?? '');
        if (!$db || !$tableName || !$action) { echo json_encode(['error' => 'Fields required.']); break; }
        try {
            switch ($action) {
                case 'drop':      $dbInstance->dropPartition($db, $tableName, $partName); break;
                case 'truncate':  $dbInstance->truncatePartition($db, $tableName, $partName); break;
                case 'optimize':  $dbInstance->optimizePartition($db, $tableName, $partName); break;
                case 'rebuild':   $dbInstance->rebuildPartition($db, $tableName, $partName); break;
                case 'add':       $dbInstance->addPartition($db, $tableName, $partDef); break;
                default: echo json_encode(['error' => 'Unknown action.']); break 2;
            }
            $auth->logActivity("{$action} partition on {$db}.{$tableName}" . ($partName ? ": {$partName}" : ''));
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'get_routines':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        try {
            $routines = $dbInstance->getRoutines($db);
            echo json_encode(['success' => true, 'routines' => $routines]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get routine definition (GET)
    case 'get_routine_definition':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        $name = trim($_GET['name'] ?? '');
        $type = trim($_GET['type'] ?? 'PROCEDURE');
        if (!$name) { echo json_encode(['error' => 'Name required.']); break; }
        try {
            $def = $dbInstance->getRoutineDefinition($db, $name, $type);
            $params = $dbInstance->getRoutineParams($db, $name);
            echo json_encode(['success' => true, 'definition' => $def, 'params' => $params]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Create routine (POST, write)
    case 'create_routine':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $sql = trim($_POST['sql'] ?? '');
        if (!$db || !$sql) { echo json_encode(['error' => 'Database and SQL required.']); break; }
        try {
            $dbInstance->createRoutine($db, $sql);
            $auth->logActivity("Created routine in {$db}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop routine (POST, write)
    case 'drop_routine':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? 'PROCEDURE');
        if (!$db || !$name) { echo json_encode(['error' => 'Database and name required.']); break; }
        try {
            $dbInstance->dropRoutine($db, $name, $type);
            $auth->logActivity("Dropped {$type}: {$db}.{$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // List events (GET)
    case 'get_events':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        try {
            $events = $dbInstance->getEvents($db);
            $scheduler = $dbInstance->getEventSchedulerStatus();
            echo json_encode(['success' => true, 'events' => $events, 'scheduler' => $scheduler]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get event definition (GET)
    case 'get_event_definition':
        if (!$db) { echo json_encode(['error' => 'No database selected.']); break; }
        $name = trim($_GET['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name required.']); break; }
        try {
            $def = $dbInstance->getEventDefinition($db, $name);
            echo json_encode(['success' => true, 'definition' => $def]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Create event (POST, write)
    case 'create_event':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $sql = trim($_POST['sql'] ?? '');
        if (!$db || !$sql) { echo json_encode(['error' => 'Database and SQL required.']); break; }
        try {
            $dbInstance->createEvent($db, $sql);
            $auth->logActivity("Created event in {$db}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop event (POST, write)
    case 'drop_event':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $name = trim($_POST['name'] ?? '');
        if (!$db || !$name) { echo json_encode(['error' => 'Database and name required.']); break; }
        try {
            $dbInstance->dropEvent($db, $name);
            $auth->logActivity("Dropped event: {$db}.{$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Toggle event enabled/disabled (POST, write)
    case 'toggle_event_status':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $name   = trim($_POST['name'] ?? '');
        $status = trim($_POST['status'] ?? '');
        if (!$db || !$name || !$status) { echo json_encode(['error' => 'Database, name, and status required.']); break; }
        try {
            $dbInstance->setEventStatus($db, $name, $status);
            $auth->logActivity("Set event {$db}.{$name} to {$status}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get processlist (GET)
    case 'get_processlist':
        try {
            $processes = $dbInstance->getProcessList();
            $currentId = $dbInstance->getCurrentConnectionId();
            echo json_encode(['success' => true, 'processes' => $processes, 'current_id' => $currentId]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Kill a process (POST, write)
    case 'kill_process':
        if ($auth->isReadOnly()) { echo json_encode(['error' => 'Write operations are disabled in read-only mode.']); break; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'Valid process ID required.']); break; }
        try {
            $dbInstance->killProcess($id);
            $auth->logActivity("Killed MySQL process {$id}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Toggle favorite (POST)
    case 'toggle_favorite':
        $favDb    = trim($_POST['db'] ?? '');
        $favTable = trim($_POST['table'] ?? '');
        if (!$favDb || !$favTable) {
            echo json_encode(['error' => 'Database and table required.']);
            break;
        }
        $username = (isset($auth) && $auth->isLoggedIn()) ? $auth->getUsername() : 'anonymous';
        $nowFavorited = ledger_favorites_toggle($username, $favDb, $favTable);
        echo json_encode([
            'success'   => true,
            'favorited' => $nowFavorited,
            'favorites' => ledger_favorites_get($username),
        ]);
        break;

    // 2FA: Generate setup (POST)
    case 'setup_2fa':
        if (!isset($auth) || !$auth->isLoggedIn()) {
            echo json_encode(['error' => 'Not authenticated.']);
            break;
        }
        require_once __DIR__ . '/includes/TOTP.php';
        $secret = LedgerTOTP::generateSecret();
        $username = $auth->getUsername();
        $uri = LedgerTOTP::getProvisioningUri($username, $secret, $config['app']['name'] ?? 'Ledger');
        echo json_encode(['success' => true, 'secret' => $secret, 'uri' => $uri]);
        break;

    // 2FA: Confirm and save (POST)
    case 'confirm_2fa':
        if (!isset($auth) || !$auth->isLoggedIn()) {
            echo json_encode(['error' => 'Not authenticated.']);
            break;
        }
        $code = trim($_POST['code'] ?? '');
        $secret = trim($_POST['secret'] ?? '');
        if (!$code || !$secret) {
            echo json_encode(['error' => 'Code and secret required.']);
            break;
        }
        require_once __DIR__ . '/includes/TOTP.php';
        if (!LedgerTOTP::verify($secret, $code)) {
            echo json_encode(['error' => 'Invalid code. Make sure your authenticator is synced and try again.']);
            break;
        }
        // Save the secret to config
        require_once __DIR__ . '/templates/settings_save.php';
        $username = $auth->getUsername();
        $newConfig = $config;
        $currentHash = $auth->getUserPasswordHash($username);
        $newConfig['security']['users'][$username] = [
            'password' => $currentHash,
            'totp_secret' => $secret,
        ];
        $written = ledger_write_config_file(__DIR__ . '/config.php', $newConfig);
        if (!$written) {
            echo json_encode(['error' => 'Could not write config.php.']);
            break;
        }
        $auth->logActivity('Enabled 2FA');
        echo json_encode(['success' => true]);
        break;

    // 2FA: Disable (POST)
    case 'disable_2fa':
        if (!isset($auth) || !$auth->isLoggedIn()) {
            echo json_encode(['error' => 'Not authenticated.']);
            break;
        }
        require_once __DIR__ . '/templates/settings_save.php';
        $username = $auth->getUsername();
        $newConfig = $config;
        $currentHash = $auth->getUserPasswordHash($username);
        // Revert to plain hash string (no totp_secret)
        $newConfig['security']['users'][$username] = $currentHash;
        $written = ledger_write_config_file(__DIR__ . '/config.php', $newConfig);
        if (!$written) {
            echo json_encode(['error' => 'Could not write config.php.']);
            break;
        }
        $auth->logActivity('Disabled 2FA');
        echo json_encode(['success' => true]);
        break;

    // Change current user's password (POST)
    case 'change_password':
        if (!isset($auth) || !$auth->isLoggedIn()) {
            echo json_encode(['error' => 'Not authenticated.']);
            break;
        }
        $current = $_POST['current'] ?? '';
        $new     = $_POST['new'] ?? '';
        if ($current === '' || $new === '') {
            echo json_encode(['error' => 'Current and new passwords are required.']);
            break;
        }
        if (strlen($new) < 6) {
            echo json_encode(['error' => 'New password must be at least 6 characters.']);
            break;
        }

        $username = $auth->getUsername();
        $currentHash = $auth->getUserPasswordHash($username);
        if ($currentHash === null) {
            echo json_encode(['error' => 'Current user not found in config.']);
            break;
        }

        // Verify current password
        $ok = false;
        if (str_starts_with($currentHash, '$2y$') || str_starts_with($currentHash, '$2a$')) {
            $ok = password_verify($current, $currentHash);
        } else {
            $ok = hash_equals($currentHash, $current);
        }
        if (!$ok) {
            echo json_encode(['error' => 'Current password is incorrect.']);
            break;
        }

        // Write new hash to config, preserving totp_secret if set
        require_once __DIR__ . '/templates/settings_save.php';
        $newConfig = $config;
        $userData = $config['security']['users'][$username] ?? null;
        $totpSecret = is_array($userData) ? ($userData['totp_secret'] ?? null) : null;
        $newHash = password_hash($new, PASSWORD_BCRYPT);
        if ($totpSecret) {
            $newConfig['security']['users'][$username] = [
                'password' => $newHash,
                'totp_secret' => $totpSecret,
            ];
        } else {
            $newConfig['security']['users'][$username] = $newHash;
        }
        $written = ledger_write_config_file(__DIR__ . '/config.php', $newConfig);
        if (!$written) {
            echo json_encode(['error' => 'Could not write config.php. Check file permissions.']);
            break;
        }

        $auth->logActivity('Changed own password');
        echo json_encode(['success' => true]);
        break;

    // Clear query history (POST)
    case 'clear_history':
        $_SESSION['ledger_query_history'] = [];
        echo json_encode(['success' => true]);
        break;

    // Delete single history item (POST)
    case 'delete_history_item':
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($_SESSION['ledger_query_history'][$idx])) {
            array_splice($_SESSION['ledger_query_history'], $idx, 1);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid index.']);
        }
        break;

    // Insert a row (POST, write)
    case 'insert_row':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $table = $_POST['table'] ?? '';
        $data = json_decode($_POST['data'] ?? '{}', true);

        if (!$db || !$table || !is_array($data) || empty($data)) {
            echo json_encode(['error' => 'Missing required fields.']);
            break;
        }

        try {
            $pdo = $dbInstance->connect($db);
            $esc = function($s) { return '`' . str_replace('`', '``', $s) . '`'; };

            $cols = [];
            $placeholders = [];
            $values = [];
            foreach ($data as $col => $val) {
                $cols[] = $esc($col);
                $placeholders[] = '?';
                $values[] = $val; // null values are handled by PDO
            }

            $sql = "INSERT INTO {$esc($table)} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $start = microtime(true);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $elapsed = microtime(true) - $start;

            $insertId = $pdo->lastInsertId();
            $auth->logQuery($db, "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ")", $elapsed);

            echo json_encode([
                'success'   => true,
                'insert_id' => $insertId ?: null,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Execute arbitrary SQL (POST, write)
    case 'execute_sql':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $sql = trim($_POST['sql'] ?? '');
        if (!$sql) {
            echo json_encode(['error' => 'No SQL provided.']);
            break;
        }

        $targetDb = $db ?: null;

        try {
            $pdo = $dbInstance->connect($targetDb);
            $start = microtime(true);
            $affected = $pdo->exec($sql);
            $elapsed = microtime(true) - $start;
            $auth->logQuery($targetDb ?? '-', $sql, $elapsed);

            echo json_encode([
                'success'  => true,
                'affected' => $affected !== false ? $affected : 0,
                'time'     => $elapsed,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Create a database (POST, write)
    case 'create_database':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $name      = trim($_POST['name'] ?? '');
        $charset   = $_POST['charset'] ?? 'utf8mb4';
        $collation = $_POST['collation'] ?? 'utf8mb4_general_ci';

        if (!$name) {
            echo json_encode(['error' => 'Database name is required.']);
            break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            echo json_encode(['error' => 'Database name can only contain letters, numbers, underscores, and hyphens.']);
            break;
        }

        try {
            $dbInstance->createDatabase($name, $charset, $collation);
            $auth->logActivity("Created database: {$name} ({$charset}/{$collation})");
            echo json_encode(['success' => true, 'name' => $name]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Drop a database (POST, write)
    case 'drop_database':
        if ($auth->isReadOnly()) {
            echo json_encode(['error' => 'Write operations are disabled in read-only mode.']);
            break;
        }

        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            echo json_encode(['error' => 'Database name is required.']);
            break;
        }

        // Block dropping system databases
        $protected = ['mysql', 'information_schema', 'performance_schema', 'sys'];
        if (in_array(strtolower($name), $protected)) {
            echo json_encode(['error' => 'Cannot drop system database: ' . $name]);
            break;
        }

        try {
            $dbInstance->dropDatabase($name);
            $auth->logActivity("Dropped database: {$name}");
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}
