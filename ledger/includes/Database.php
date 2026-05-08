<?php
/**
 * Ledger - Database Connection Manager
 */

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                throw new RuntimeException('Database config required on first call');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * @param string|null $database If provided, selects this database in the DSN
     * @return PDO Fresh connection (replaces any existing)
     */
    public function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['charset']
        );

        if ($database) {
            $dsn .= ";dbname={$database}";
        }

        $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]);

        return $this->pdo;
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    public function getDatabases(): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->query('SHOW DATABASES');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getTables(string $database): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('SHOW TABLE STATUS');
        return $stmt->fetchAll();
    }

    public function getViews(string $database): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT TABLE_NAME AS name, VIEW_DEFINITION AS definition, DEFINER AS definer,
                   SECURITY_TYPE AS security, CHECK_OPTION AS check_option
            FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ');
        $stmt->execute([$database]);
        return $stmt->fetchAll();
    }

    public function getViewDefinition(string $database, string $view): ?string
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('SHOW CREATE VIEW `' . $this->escapeIdentifier($view) . '`');
        $row = $stmt->fetch();
        return $row['Create View'] ?? null;
    }

    public function createView(string $database, string $name, string $definition, bool $replace = false): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('View name must be alphanumeric + underscores.');
        }
        $pdo = $this->connect($database);
        $prefix = $replace ? 'CREATE OR REPLACE' : 'CREATE';
        $pdo->exec("{$prefix} VIEW `" . $this->escapeIdentifier($name) . "` AS {$definition}");
        return true;
    }

    public function dropView(string $database, string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid view name.');
        }
        $pdo = $this->connect($database);
        $pdo->exec('DROP VIEW IF EXISTS `' . $this->escapeIdentifier($name) . '`');
        return true;
    }

    public function getTableStatus(string $database, string $table): ?array
    {
        $pdo = $this->connect($database);
        // SHOW TABLE STATUS LIKE doesn't accept prepared statement placeholders
        // on some MySQL versions. Escape the identifier into the query.
        $escaped = str_replace(['\\', "'", '_', '%'], ['\\\\', "\\'", '\\_', '\\%'], $table);
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE '" . $escaped . "'");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getPartitions(string $database, string $table): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                PARTITION_NAME,
                PARTITION_METHOD,
                PARTITION_EXPRESSION,
                PARTITION_DESCRIPTION,
                TABLE_ROWS,
                DATA_LENGTH,
                INDEX_LENGTH,
                PARTITION_ORDINAL_POSITION
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
            ORDER BY PARTITION_ORDINAL_POSITION
        ');
        $stmt->execute([$database, $table]);
        return $stmt->fetchAll();
    }

    public function getPartitionInfo(string $database, string $table): ?array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                PARTITION_METHOD,
                PARTITION_EXPRESSION,
                SUBPARTITION_METHOD,
                SUBPARTITION_EXPRESSION
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$database, $table]);
        $row = $stmt->fetch();
        if (!$row || !$row['PARTITION_METHOD']) return null;
        return $row;
    }

    /**
     * @param string $partitionSql Raw PARTITION BY clause (e.g. "PARTITION BY HASH(id) PARTITIONS 4")
     */
    public function partitionTable(string $database, string $table, string $partitionSql): bool
    {
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} {$partitionSql}");
        return true;
    }

    public function removePartitioning(string $database, string $table): bool
    {
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} REMOVE PARTITIONING");
        return true;
    }

    public function dropPartition(string $database, string $table, string $partitionName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $partitionName)) throw new InvalidArgumentException('Invalid partition name.');
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} DROP PARTITION `{$partitionName}`");
        return true;
    }

    public function truncatePartition(string $database, string $table, string $partitionName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $partitionName)) throw new InvalidArgumentException('Invalid partition name.');
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} TRUNCATE PARTITION `{$partitionName}`");
        return true;
    }

    public function optimizePartition(string $database, string $table, string $partitionName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $partitionName)) throw new InvalidArgumentException('Invalid partition name.');
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} OPTIMIZE PARTITION `{$partitionName}`");
        return true;
    }

    public function rebuildPartition(string $database, string $table, string $partitionName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $partitionName)) throw new InvalidArgumentException('Invalid partition name.');
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} REBUILD PARTITION `{$partitionName}`");
        return true;
    }

    public function addPartition(string $database, string $table, string $partitionDef): bool
    {
        $pdo = $this->connect($database);
        $safe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$safe} ADD PARTITION ({$partitionDef})");
        return true;
    }

    public function optimizeTable(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('OPTIMIZE TABLE `' . $this->escapeIdentifier($table) . '`');
        return $stmt->fetchAll();
    }

    public function getExactRowCount(string $database, string $table): int
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('SELECT COUNT(*) FROM `' . $this->escapeIdentifier($table) . '`');
        return (int) $stmt->fetchColumn();
    }

    public function getColumns(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->prepare('SHOW FULL COLUMNS FROM `' . $this->escapeIdentifier($table) . '`');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getIndexes(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . $this->escapeIdentifier($table) . '`');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Add an index to a table.
     *
     * @param string $type   'PRIMARY' | 'UNIQUE' | 'INDEX' | 'FULLTEXT' | 'SPATIAL'
     * @param array  $columns Column names (ordered). Each entry may be "col" or "col(10)" for prefix.
     * @param string $name    Optional index name. Ignored for PRIMARY.
     */
    public function addIndex(string $database, string $table, string $type, array $columns, string $name = ''): bool
    {
        $type = strtoupper(trim($type));
        $allowed = ['PRIMARY', 'UNIQUE', 'INDEX', 'FULLTEXT', 'SPATIAL'];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid index type: {$type}");
        }
        if (empty($columns)) {
            throw new \InvalidArgumentException('At least one column is required.');
        }

        // Build column list — allow optional (N) length suffix
        $colParts = [];
        foreach ($columns as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (preg_match('/^([A-Za-z0-9_]+)\s*(?:\(\s*(\d+)\s*\))?$/', $c, $m)) {
                $colEsc = '`' . $this->escapeIdentifier($m[1]) . '`';
                if (!empty($m[2])) $colEsc .= '(' . (int)$m[2] . ')';
                $colParts[] = $colEsc;
            } else {
                throw new \InvalidArgumentException("Invalid column spec: {$c}");
            }
        }
        if (empty($colParts)) {
            throw new \InvalidArgumentException('No valid columns provided.');
        }

        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';
        $colList = implode(', ', $colParts);

        if ($type === 'PRIMARY') {
            $sql = "ALTER TABLE {$tableSafe} ADD PRIMARY KEY ({$colList})";
        } else {
            // Name handling: use provided name or let MySQL auto-name
            $nameClause = '';
            if ($name !== '') {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    throw new \InvalidArgumentException('Invalid index name.');
                }
                $nameClause = ' `' . $this->escapeIdentifier($name) . '`';
            }
            if ($type === 'UNIQUE') {
                $sql = "ALTER TABLE {$tableSafe} ADD UNIQUE{$nameClause} ({$colList})";
            } elseif ($type === 'FULLTEXT') {
                $sql = "ALTER TABLE {$tableSafe} ADD FULLTEXT{$nameClause} ({$colList})";
            } elseif ($type === 'SPATIAL') {
                $sql = "ALTER TABLE {$tableSafe} ADD SPATIAL{$nameClause} ({$colList})";
            } else { // INDEX
                $sql = "ALTER TABLE {$tableSafe} ADD INDEX{$nameClause} ({$colList})";
            }
        }

        $pdo = $this->connect($database);
        $pdo->exec($sql);
        return true;
    }

    /**
     * Drop an index. Use 'PRIMARY' as the name to drop the primary key.
     */
    public function dropIndex(string $database, string $table, string $indexName): bool
    {
        $indexName = trim($indexName);
        if ($indexName === '') {
            throw new \InvalidArgumentException('Index name is required.');
        }

        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo = $this->connect($database);

        if (strtoupper($indexName) === 'PRIMARY') {
            $sql = "ALTER TABLE {$tableSafe} DROP PRIMARY KEY";
        } else {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $indexName)) {
                throw new \InvalidArgumentException('Invalid index name.');
            }
            $sql = "ALTER TABLE {$tableSafe} DROP INDEX `" . $this->escapeIdentifier($indexName) . '`';
        }

        $pdo->exec($sql);
        return true;
    }

    public function getForeignKeys(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->prepare("
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_SCHEMA,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.TABLE_SCHEMA = :db
                AND kcu.TABLE_NAME = :tbl
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ");
        $stmt->execute(['db' => $database, 'tbl' => $table]);
        return $stmt->fetchAll();
    }

    public function getReferencedBy(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->prepare("
            SELECT
                kcu.TABLE_NAME AS referencing_table,
                kcu.COLUMN_NAME AS referencing_column,
                kcu.REFERENCED_COLUMN_NAME AS local_column,
                kcu.CONSTRAINT_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.REFERENCED_TABLE_SCHEMA = :db
                AND kcu.REFERENCED_TABLE_NAME = :tbl
            ORDER BY kcu.TABLE_NAME, kcu.ORDINAL_POSITION
        ");
        $stmt->execute(['db' => $database, 'tbl' => $table]);
        return $stmt->fetchAll();
    }

    public function getCreateStatement(string $database, string $table): string
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('SHOW CREATE TABLE `' . $this->escapeIdentifier($table) . '`');
        $row = $stmt->fetch();
        return $row['Create Table'] ?? $row['Create View'] ?? '';
    }

    /**
     * Parse a SHOW CREATE TABLE output into phpMyAdmin-style parts.
     *
     * Returns an array with:
     *   'create'         — CREATE TABLE with ONLY columns (no PK, indexes, FKs, AUTO_INCREMENT)
     *                      followed by the engine/charset clause
     *   'primary_key'    — "ADD PRIMARY KEY (...)" or ''
     *   'keys'           — list of "ADD UNIQUE KEY ..." / "ADD KEY ..." / "ADD FULLTEXT KEY ..." strings
     *   'foreign_keys'   — list of "ADD CONSTRAINT ... FOREIGN KEY ..." strings
     *   'auto_increment' — ['col' => column_name, 'def' => raw column definition, 'next' => next value] or null
     *
     * This mirrors the 4-pass dump style used by phpMyAdmin where CREATE TABLE
     * contains only columns, and keys/FKs/auto_increment are emitted as separate
     * ALTER TABLE statements after all tables have been created.
     */
    public function splitCreateStatement(string $createSql): array
    {
        $result = [
            'create'         => '',
            'primary_key'    => '',
            'keys'           => [],
            'foreign_keys'   => [],
            'auto_increment' => null,
        ];

        // Split off the opening line and the trailing ") ENGINE=... ;" part
        // SHOW CREATE TABLE output shape:
        //   CREATE TABLE `name` (
        //     `col` ...,
        //     ...
        //     PRIMARY KEY (...),
        //     KEY `x` (...),
        //     CONSTRAINT `fk_x` FOREIGN KEY ...
        //   ) ENGINE=InnoDB ... ;
        if (!preg_match('/^(CREATE TABLE[^(]*\()(.*)(\)[^()]*)$/s', trim($createSql), $m)) {
            // Fallback: return original as-is
            $result['create'] = $createSql;
            return $result;
        }
        $openLine = $m[1];       // "CREATE TABLE `name` ("
        $body     = $m[2];       // everything between the outer parens
        $tail     = $m[3];       // ") ENGINE=... CHARSET=... COLLATE=... [AUTO_INCREMENT=N]"

        // Strip AUTO_INCREMENT=N from the tail — phpMyAdmin omits it on the CREATE
        $tail = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $tail);

        // Split body into top-level lines, respecting parens so "(n,m)" or "(col1, col2)" don't split
        $lines = $this->splitDefinitionBody($body);

        $columns = [];
        $autoIncCol = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^PRIMARY\s+KEY\s*\(/i', $line)) {
                $result['primary_key'] = 'ADD ' . rtrim($line, ',');
            } elseif (preg_match('/^UNIQUE\s+KEY\s/i', $line) || preg_match('/^FULLTEXT\s+KEY\s/i', $line) || preg_match('/^SPATIAL\s+KEY\s/i', $line) || preg_match('/^KEY\s/i', $line)) {
                $result['keys'][] = 'ADD ' . rtrim($line, ',');
            } elseif (preg_match('/^CONSTRAINT\s.*FOREIGN\s+KEY/i', $line)) {
                $result['foreign_keys'][] = 'ADD ' . rtrim($line, ',');
            } elseif (preg_match('/^`([^`]+)`/', $line, $cm)) {
                // Column definition. Detect AUTO_INCREMENT and strip it for the CREATE.
                $colName = $cm[1];
                if (preg_match('/\bAUTO_INCREMENT\b/i', $line)) {
                    $autoIncCol = $colName;
                    // Strip AUTO_INCREMENT from the column's CREATE-line version
                    $lineStripped = preg_replace('/\s*AUTO_INCREMENT/i', '', $line);
                    $columns[] = rtrim($lineStripped, ',');
                } else {
                    $columns[] = rtrim($line, ',');
                }
            } else {
                // Unknown clause — preserve as-is (e.g. CHECK constraints)
                $columns[] = rtrim($line, ',');
            }
        }

        // Reassemble CREATE with columns only
        $result['create'] = $openLine . "\n  " . implode(",\n  ", $columns) . "\n" . $tail;

        if ($autoIncCol !== null) {
            // Fetch current AUTO_INCREMENT value for the MODIFY ... AUTO_INCREMENT clause
            $result['auto_increment'] = [
                'col' => $autoIncCol,
            ];
        }

        return $result;
    }

    /**
     * Split the body of a CREATE TABLE parenthesized block into top-level lines,
     * treating commas inside nested parens as part of a single line.
     *
     * @internal
     */
    private function splitDefinitionBody(string $body): array
    {
        $lines = [];
        $depth = 0;
        $buf = '';
        $len = strlen($body);
        $inBacktick = false;
        $inSingleQuote = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '`' && !$inSingleQuote) {
                $inBacktick = !$inBacktick;
                $buf .= $c;
                continue;
            }
            if ($c === "'" && !$inBacktick) {
                // Handle escaped quote '' inside string
                if ($inSingleQuote && $i + 1 < $len && $body[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inSingleQuote = !$inSingleQuote;
                $buf .= $c;
                continue;
            }
            if ($inBacktick || $inSingleQuote) {
                $buf .= $c;
                continue;
            }
            if ($c === '(') { $depth++; $buf .= $c; continue; }
            if ($c === ')') { $depth--; $buf .= $c; continue; }
            if ($c === ',' && $depth === 0) {
                $lines[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') $lines[] = $buf;
        return $lines;
    }

    /**
     * Get the next AUTO_INCREMENT value for a table. Returns null if the table
     * has no AUTO_INCREMENT column.
     */
    public function getAutoIncrementValue(string $database, string $table): ?int
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$database, $table]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (int)$val : null;
    }

    /**
     * Get a column's exact MySQL definition (TYPE + nullability + default + COMMENT)
     * suitable for use in an ALTER TABLE ... MODIFY clause.
     */
    public function getColumnDefinition(string $database, string $table, string $column): string
    {
        // Re-use SHOW CREATE TABLE to get the authoritative column line
        $createSql = $this->getCreateStatement($database, $table);
        if (!preg_match('/^(CREATE TABLE[^(]*\()(.*)(\)[^()]*)$/s', trim($createSql), $m)) {
            return '';
        }
        $lines = $this->splitDefinitionBody($m[2]);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^`' . preg_quote($column, '/') . '`\s+(.*)$/', $line, $dm)) {
                return rtrim($dm[1], ',');
            }
        }
        return '';
    }

    /**
     * Paginated table browse with optional ordering, search, and FK filtering.
     * @return array{rows: array, total: int, pages: int, page: int, columns: array}
     */
    public function browseTable(string $database, string $table, int $page = 1, int $perPage = 50, ?string $orderBy = null, string $orderDir = 'ASC', ?string $search = null, ?string $fkCol = null, ?string $fkVal = null): array
    {
        $pdo = $this->connect($database);
        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';

        // Count total
        $countSql = "SELECT COUNT(*) FROM {$tableSafe}";
        $params = [];

        // FK exact-match filter (takes priority over search)
        $whereClauses = [];
        if ($fkCol !== null && $fkVal !== null) {
            $whereClauses[] = '`' . $this->escapeIdentifier($fkCol) . '` = :fk_val';
            $params['fk_val'] = $fkVal;
        } elseif ($search) {
            // Search filter
            $columns = $this->getColumns($database, $table);
            foreach ($columns as $col) {
                $whereClauses[] = '`' . $this->escapeIdentifier($col['Field']) . '` LIKE :search_' . $col['Field'];
                $params['search_' . $col['Field']] = "%{$search}%";
            }
        }

        $where = '';
        if (!empty($whereClauses)) {
            if ($fkCol !== null) {
                $where = ' WHERE ' . implode(' AND ', $whereClauses);
            } else {
                $where = ' WHERE ' . implode(' OR ', $whereClauses);
            }
            $countSql .= $where;
        }

        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch rows
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM {$tableSafe}";
        if (!empty($whereClauses)) {
            $sql .= $where;
        }
        if ($orderBy) {
            $sql .= ' ORDER BY `' . $this->escapeIdentifier($orderBy) . '` ' . ($orderDir === 'DESC' ? 'DESC' : 'ASC');
        }
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Build display SQL with params substituted
        $displaySql = $sql;
        foreach ($params as $key => $val) {
            $quoted = $pdo->quote($val);
            $displaySql = str_replace(':' . $key, $quoted, $displaySql);
        }

        return [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'sql'        => $displaySql,
        ];
    }

    /**
     * Execute a SQL query and return results or affected row count.
     * @return array{columns: array, rows: array, affected: int, time: float}
     */
    public function executeQuery(string $database, string $sql): array
    {
        $pdo = $this->connect($database);
        $start = microtime(true);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $elapsed = microtime(true) - $start;

            $isSelect = stripos(trim($sql), 'SELECT') === 0
                || stripos(trim($sql), 'SHOW') === 0
                || stripos(trim($sql), 'DESCRIBE') === 0
                || stripos(trim($sql), 'DESC ') === 0
                || stripos(trim($sql), 'EXPLAIN') === 0;

            if ($isSelect) {
                $rows = $stmt->fetchAll();
                $columns = [];
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                } else {
                    // Try to get column names from metadata
                    $colCount = $stmt->columnCount();
                    for ($i = 0; $i < $colCount; $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $columns[] = $meta['name'];
                    }
                }
                return [
                    'success'  => true,
                    'type'     => 'select',
                    'columns'  => $columns,
                    'rows'     => $rows,
                    'count'    => count($rows),
                    'time'     => round($elapsed, 4),
                ];
            } else {
                return [
                    'success'  => true,
                    'type'     => 'modify',
                    'affected' => $stmt->rowCount(),
                    'time'     => round($elapsed, 4),
                ];
            }
        } catch (PDOException $e) {
            $elapsed = microtime(true) - $start;
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
                'time'    => round($elapsed, 4),
            ];
        }
    }

    public function getServerInfo(): array
    {
        $pdo = $this->connect();
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        $vars = [];
        $stmt = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('version','version_comment','hostname','port','datadir','character_set_server','collation_server','max_connections','innodb_buffer_pool_size','uptime')");
        foreach ($stmt->fetchAll() as $row) {
            $vars[$row['Variable_name']] = $row['Value'];
        }

        // Get global status
        $status = [];
        $stmt = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions','Bytes_received','Bytes_sent')");
        foreach ($stmt->fetchAll() as $row) {
            $status[$row['Variable_name']] = $row['Value'];
        }

        return [
            'version'   => $version,
            'variables' => $vars,
            'status'    => $status,
        ];
    }

    /**
     * Get the full processlist. Requires PROCESS privilege to see other users'
     * threads; without it, only threads owned by the current user are returned.
     *
     * @return array<array{id:int,user:string,host:string,db:?string,command:string,time:int,state:?string,info:?string}>
     */
    public function getProcessList(): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->query('SHOW FULL PROCESSLIST');
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            // Column case varies between MySQL and MariaDB — normalize.
            $norm = [];
            foreach ($row as $k => $v) {
                if (is_int($k)) continue;
                $norm[strtolower($k)] = $v;
            }
            $result[] = [
                'id'      => (int)($norm['id'] ?? 0),
                'user'    => (string)($norm['user'] ?? ''),
                'host'    => (string)($norm['host'] ?? ''),
                'db'      => $norm['db'] ?? null,
                'command' => (string)($norm['command'] ?? ''),
                'time'    => (int)($norm['time'] ?? 0),
                'state'   => $norm['state'] ?? null,
                'info'    => $norm['info'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Returns the connection ID of this Ledger session's MySQL connection.
     * Used so the UI can mark "this is you" and block self-kill.
     */
    public function getCurrentConnectionId(): int
    {
        $pdo = $this->connect();
        $id = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        return (int)$id;
    }

    /**
     * Kill a MySQL thread by ID. Refuses to kill the caller's own connection.
     *
     * @throws InvalidArgumentException if attempting to kill self
     * @throws PDOException on KILL failure (no privilege, thread gone, etc.)
     */
    public function killProcess(int $id): bool
    {
        $pdo = $this->connect();
        $ownId = $this->getCurrentConnectionId();
        if ($id === $ownId) {
            throw new InvalidArgumentException('Refusing to kill the current Ledger connection.');
        }
        // KILL doesn't take placeholders in all versions; cast to int above protects us.
        $pdo->exec('KILL ' . $id);
        return true;
    }

    /**

     * Generate CREATE TABLE + INSERT statements for a single table.
     * Does not include a file header — caller is responsible for that.
     */
    /**
     * Export a table as SQL.
     *
     * @param string $mode 'full' (default, structure + data), 'structure' (CREATE only), or 'data' (INSERTs only).
     */
    public function exportTable(string $database, string $table, string $mode = 'full'): string
    {
        $pdo = $this->connect($database);
        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';

        $output = '';

        // Create statement (skip for data-only mode)
        if ($mode !== 'data') {
            $output .= $this->getCreateStatement($database, $table) . ";\n\n";
        }

        // Data (skip for structure-only mode)
        if ($mode !== 'structure') {
            $stmt = $pdo->query("SELECT * FROM {$tableSafe}");
            $rows = $stmt->fetchAll();

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colList = implode('`, `', $columns);

                foreach ($rows as $row) {
                    $values = array_map(function ($val) use ($pdo) {
                        if ($val === null) return 'NULL';
                        return $pdo->quote($val);
                    }, array_values($row));
                    $output .= "INSERT INTO {$tableSafe} (`{$colList}`) VALUES (" . implode(', ', $values) . ");\n";
                }
            }
        }

        return $output;
    }

    public function exportTableCsv(string $database, string $table): string
    {
        $pdo = $this->connect($database);
        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';

        $stmt = $pdo->query("SELECT * FROM {$tableSafe}");
        $rows = $stmt->fetchAll();

        $output = fopen('php://temp', 'r+');
        if (!empty($rows)) {
            fputcsv($output, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($output, array_values($row));
            }
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function dropTable(string $database, string $table): bool
    {
        $pdo = $this->connect($database);
        $pdo->exec('DROP TABLE `' . $this->escapeIdentifier($table) . '`');
        return true;
    }

    public function truncateTable(string $database, string $table): bool
    {
        $pdo = $this->connect($database);
        $pdo->exec('TRUNCATE TABLE `' . $this->escapeIdentifier($table) . '`');
        return true;
    }

    public function renameTable(string $database, string $oldName, string $newName): bool
    {
        $pdo = $this->connect($database);
        $pdo->exec('RENAME TABLE `' . $this->escapeIdentifier($oldName) . '` TO `' . $this->escapeIdentifier($newName) . '`');
        return true;
    }

    /**
     * @param bool $withData If true, copies structure + data. If false, structure only.
     * @param string|null $destDatabase Target database for cross-DB copy (null = same DB)
     */
    public function copyTable(string $database, string $source, string $destination, bool $withData = true, ?string $destDatabase = null): bool
    {
        $pdo = $this->connect($database);
        $srcSafe = '`' . $this->escapeIdentifier($database) . '`.`' . $this->escapeIdentifier($source) . '`';
        $destDb = $destDatabase ?: $database;
        $dstSafe = '`' . $this->escapeIdentifier($destDb) . '`.`' . $this->escapeIdentifier($destination) . '`';

        // Create structure
        $pdo->exec("CREATE TABLE {$dstSafe} LIKE {$srcSafe}");

        // Copy data if requested
        if ($withData) {
            $pdo->exec("INSERT INTO {$dstSafe} SELECT * FROM {$srcSafe}");
        }

        return true;
    }

    public function moveTableToDatabase(string $sourceDb, string $table, string $targetDb): bool
    {
        $pdo = $this->connect();
        $src = '`' . $this->escapeIdentifier($sourceDb) . '`.`' . $this->escapeIdentifier($table) . '`';
        $dst = '`' . $this->escapeIdentifier($targetDb) . '`.`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("RENAME TABLE {$src} TO {$dst}");
        return true;
    }

    /**
     * @param array $options Keys: engine, row_format, collation, comment, auto_increment
     */
    public function alterTableOptions(string $database, string $table, array $options): bool
    {
        $parts = [];
        if (!empty($options['engine'])) {
            $parts[] = 'ENGINE = ' . preg_replace('/[^a-zA-Z0-9_]/', '', $options['engine']);
        }
        if (!empty($options['collation'])) {
            $coll = preg_replace('/[^a-zA-Z0-9_]/', '', $options['collation']);
            // Derive charset from collation (charset is everything before the first underscore)
            $charset = explode('_', $coll)[0];
            $parts[] = "CONVERT TO CHARACTER SET {$charset} COLLATE {$coll}";
        }
        if (!empty($options['row_format'])) {
            $parts[] = 'ROW_FORMAT = ' . preg_replace('/[^a-zA-Z0-9_]/', '', $options['row_format']);
        }
        if (array_key_exists('comment', $options)) {
            $pdo = $this->connect($database);
            $parts[] = 'COMMENT = ' . $pdo->quote($options['comment']);
        }
        if (empty($parts)) return false;

        $pdo = $this->connect($database);
        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';
        $pdo->exec("ALTER TABLE {$tableSafe} " . implode(', ', $parts));
        return true;
    }

    public function analyzeTable(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('ANALYZE TABLE `' . $this->escapeIdentifier($table) . '`');
        return $stmt->fetchAll();
    }

    public function checkTable(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('CHECK TABLE `' . $this->escapeIdentifier($table) . '`');
        return $stmt->fetchAll();
    }

    public function repairTable(string $database, string $table): array
    {
        $pdo = $this->connect($database);
        $stmt = $pdo->query('REPAIR TABLE `' . $this->escapeIdentifier($table) . '`');
        return $stmt->fetchAll();
    }

    public function getEngines(): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->query('SHOW ENGINES');
        $rows = $stmt->fetchAll();
        $engines = [];
        foreach ($rows as $row) {
            if (($row['Support'] ?? '') === 'YES' || ($row['Support'] ?? '') === 'DEFAULT') {
                $engines[] = $row['Engine'];
            }
        }
        return $engines;
    }

    public function getCollations(): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->query('SHOW COLLATION');
        $rows = $stmt->fetchAll();
        return array_map(fn($r) => $r['Collation'], $rows);
    }

    public function getTriggers(string $database, string $table): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                TRIGGER_NAME       AS name,
                ACTION_TIMING      AS timing,
                EVENT_MANIPULATION AS event,
                ACTION_STATEMENT   AS body,
                ACTION_ORIENTATION AS orientation,
                DEFINER            AS definer,
                CREATED            AS created
            FROM information_schema.TRIGGERS
            WHERE EVENT_OBJECT_SCHEMA = ?
              AND EVENT_OBJECT_TABLE = ?
            ORDER BY ACTION_TIMING, EVENT_MANIPULATION, TRIGGER_NAME
        ');
        $stmt->execute([$database, $table]);
        return $stmt->fetchAll();
    }

    /**
     * @param string $timing BEFORE|AFTER
     * @param string $event INSERT|UPDATE|DELETE
     * @param string $body Raw SQL body (typically BEGIN...END)
     */
    public function createTrigger(
        string $database,
        string $name,
        string $timing,
        string $event,
        string $table,
        string $body
    ): bool {
        $timing = strtoupper($timing);
        $event  = strtoupper($event);
        if (!in_array($timing, ['BEFORE', 'AFTER']))              throw new InvalidArgumentException('Invalid timing.');
        if (!in_array($event, ['INSERT', 'UPDATE', 'DELETE']))    throw new InvalidArgumentException('Invalid event.');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name))              throw new InvalidArgumentException('Trigger name must be alphanumeric + underscores.');

        $pdo = $this->connect($database);
        $nameSafe  = '`' . $this->escapeIdentifier($name) . '`';
        $tableSafe = '`' . $this->escapeIdentifier($table) . '`';
        $sql = "CREATE TRIGGER {$nameSafe} {$timing} {$event} ON {$tableSafe} FOR EACH ROW {$body}";
        $pdo->exec($sql);
        return true;
    }

    public function dropTrigger(string $database, string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid trigger name.');
        }
        $pdo = $this->connect($database);
        $pdo->exec('DROP TRIGGER IF EXISTS `' . $this->escapeIdentifier($name) . '`');
        return true;
    }

    public function getRoutines(string $database): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                ROUTINE_NAME      AS name,
                ROUTINE_TYPE      AS type,
                DTD_IDENTIFIER    AS returns_type,
                ROUTINE_COMMENT   AS comment,
                DEFINER           AS definer,
                CREATED           AS created,
                LAST_ALTERED      AS modified,
                SECURITY_TYPE     AS security
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = ?
            ORDER BY ROUTINE_TYPE, ROUTINE_NAME
        ');
        $stmt->execute([$database]);
        return $stmt->fetchAll();
    }

    /**
     * @return string|null The full CREATE PROCEDURE/FUNCTION statement, or null if not found
     */
    public function getRoutineDefinition(string $database, string $name, string $type = 'PROCEDURE'): ?string
    {
        $pdo = $this->connect($database);
        $type = strtoupper($type);
        $nameSafe = '`' . $this->escapeIdentifier($name) . '`';
        if ($type === 'FUNCTION') {
            $row = $pdo->query("SHOW CREATE FUNCTION {$nameSafe}")->fetch();
            return $row['Create Function'] ?? null;
        } else {
            $row = $pdo->query("SHOW CREATE PROCEDURE {$nameSafe}")->fetch();
            return $row['Create Procedure'] ?? null;
        }
    }

    public function getRoutineParams(string $database, string $name): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                PARAMETER_NAME AS name,
                PARAMETER_MODE AS mode,
                DATA_TYPE      AS type,
                DTD_IDENTIFIER AS full_type,
                ORDINAL_POSITION AS position
            FROM information_schema.PARAMETERS
            WHERE SPECIFIC_SCHEMA = ? AND SPECIFIC_NAME = ?
            ORDER BY ORDINAL_POSITION
        ');
        $stmt->execute([$database, $name]);
        return $stmt->fetchAll();
    }

    public function createRoutine(string $database, string $sql): bool
    {
        $pdo = $this->connect($database);
        $pdo->exec($sql);
        return true;
    }

    public function dropRoutine(string $database, string $name, string $type = 'PROCEDURE'): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid routine name.');
        }
        $type = strtoupper($type) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
        $pdo = $this->connect($database);
        $pdo->exec("DROP {$type} IF EXISTS `" . $this->escapeIdentifier($name) . '`');
        return true;
    }

    public function getEvents(string $database): array
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('
            SELECT
                EVENT_NAME       AS name,
                EVENT_TYPE       AS type,
                STATUS           AS status,
                EXECUTE_AT       AS execute_at,
                INTERVAL_VALUE   AS interval_value,
                INTERVAL_FIELD   AS interval_field,
                STARTS           AS starts,
                ENDS             AS ends,
                ON_COMPLETION    AS on_completion,
                DEFINER          AS definer,
                CREATED          AS created,
                LAST_ALTERED     AS modified,
                LAST_EXECUTED    AS last_executed,
                EVENT_COMMENT    AS comment
            FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = ?
            ORDER BY EVENT_NAME
        ');
        $stmt->execute([$database]);
        return $stmt->fetchAll();
    }

    /**
     * @return string|null The full CREATE EVENT statement, or null if not found
     */
    public function getEventDefinition(string $database, string $name): ?string
    {
        $pdo = $this->connect($database);
        $nameSafe = '`' . $this->escapeIdentifier($name) . '`';
        $row = $pdo->query("SHOW CREATE EVENT {$nameSafe}")->fetch();
        return $row['Create Event'] ?? null;
    }

    public function createEvent(string $database, string $sql): bool
    {
        $pdo = $this->connect($database);
        $pdo->exec($sql);
        return true;
    }

    public function dropEvent(string $database, string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid event name.');
        }
        $pdo = $this->connect($database);
        $pdo->exec('DROP EVENT IF EXISTS `' . $this->escapeIdentifier($name) . '`');
        return true;
    }

    public function setEventStatus(string $database, string $name, string $status): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid event name.');
        }
        $status = strtoupper($status);
        if (!in_array($status, ['ENABLE', 'DISABLE'], true)) {
            throw new InvalidArgumentException('Invalid status — must be ENABLE or DISABLE.');
        }
        $pdo = $this->connect($database);
        $pdo->exec('ALTER EVENT `' . $this->escapeIdentifier($name) . '` ' . $status);
        return true;
    }

    /**
     * Returns 'ON', 'OFF', or 'DISABLED' (the last means it's compiled out entirely).
     */
    public function getEventSchedulerStatus(): string
    {
        $pdo = $this->connect();
        $row = $pdo->query("SHOW VARIABLES LIKE 'event_scheduler'")->fetch();
        return strtoupper($row['Value'] ?? 'OFF');
    }

    public function createDatabase(string $name, string $charset = 'utf8mb4', string $collation = 'utf8mb4_general_ci'): bool
    {
        $pdo = $this->connect();
        $sql = 'CREATE DATABASE `' . $this->escapeIdentifier($name) . '`'
            . ' CHARACTER SET ' . preg_replace('/[^a-zA-Z0-9_]/', '', $charset)
            . ' COLLATE ' . preg_replace('/[^a-zA-Z0-9_]/', '', $collation);
        $pdo->exec($sql);
        return true;
    }

    public function dropDatabase(string $name): bool
    {
        $pdo = $this->connect();
        $pdo->exec('DROP DATABASE `' . $this->escapeIdentifier($name) . '`');
        return true;
    }

    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * Search for a value across all tables in a database.
     * Returns matches grouped by table.
     */
    public function searchAcrossTables(string $database, string $searchTerm, int $maxPerTable = 5): array
    {
        $pdo = $this->connect($database);
        $tables = $this->getTables($database);
        $results = [];
        $tablesSearched = 0;
        $totalMatches = 0;

        foreach ($tables as $table) {
            $tableName = $table['Name'];
            $columns = $this->getColumns($database, $tableName);

            // Build searchable columns (skip BLOB/BINARY)
            $searchCols = [];
            foreach ($columns as $col) {
                $type = strtolower($col['Type']);
                if (preg_match('/blob|binary|geometry|point|linestring|polygon/i', $type)) continue;
                $searchCols[] = $col['Field'];
            }
            if (empty($searchCols)) continue;

            // Build WHERE clause
            $whereParts = [];
            $params = [];
            foreach ($searchCols as $i => $colName) {
                $paramKey = 's' . $i;
                $whereParts[] = '`' . $this->escapeIdentifier($colName) . '` LIKE :' . $paramKey;
                $params[$paramKey] = '%' . $searchTerm . '%';
            }

            $sql = 'SELECT * FROM `' . $this->escapeIdentifier($tableName) . '` WHERE '
                . implode(' OR ', $whereParts) . ' LIMIT ' . ($maxPerTable + 1);

            $tablesSearched++;

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                if (empty($rows)) continue;

                $hasMore = count($rows) > $maxPerTable;
                if ($hasMore) $rows = array_slice($rows, 0, $maxPerTable);

                // Find which columns matched
                $matchedCols = [];
                foreach ($rows as $row) {
                    foreach ($searchCols as $colName) {
                        $val = $row[$colName] ?? '';
                        if ($val !== null && stripos((string)$val, $searchTerm) !== false) {
                            $matchedCols[$colName] = true;
                        }
                    }
                }

                // Get PK column
                $pkCol = null;
                foreach ($columns as $col) {
                    if ($col['Key'] === 'PRI') { $pkCol = $col['Field']; break; }
                }

                $totalMatches += count($rows);
                $results[] = [
                    'table'       => $tableName,
                    'columns'     => array_keys($matchedCols),
                    'pk'          => $pkCol,
                    'rows'        => $rows,
                    'has_more'    => $hasMore,
                    'match_count' => count($rows),
                ];

            } catch (\PDOException $e) {
                // Skip tables that error (e.g. views with missing deps)
                continue;
            }
        }

        return [
            'results'         => $results,
            'tables_searched' => $tablesSearched,
            'tables_matched'  => count($results),
            'total_matches'   => $totalMatches,
            'search_term'     => $searchTerm,
        ];
    }

    /**
     * Execute a SQL dump — splits by statement delimiter and runs each one.
     * Returns array of results per statement.
     */
    public function executeSqlDump(?string $database, string $sql): array
    {
        $pdo = $this->connect($database);
        $results = [];
        $statements = $this->splitSqlStatements($sql);

        foreach ($statements as $i => $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt)) continue;

            $start = microtime(true);
            try {
                $affected = $pdo->exec($stmt);
                $elapsed = microtime(true) - $start;
                $results[] = [
                    'success' => true,
                    'sql'     => mb_substr($stmt, 0, 120) . (mb_strlen($stmt) > 120 ? '…' : ''),
                    'rows'    => $affected !== false ? $affected : 0,
                    'time'    => $elapsed,
                ];
            } catch (\PDOException $e) {
                $elapsed = microtime(true) - $start;
                $results[] = [
                    'success' => false,
                    'sql'     => mb_substr($stmt, 0, 120) . (mb_strlen($stmt) > 120 ? '…' : ''),
                    'error'   => $e->getMessage(),
                    'time'    => $elapsed,
                ];
            }
        }

        return $results;
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;

        while ($i < $len) {
            $ch = $sql[$i];

            // Skip escaped characters inside strings
            if ($ch === '\\' && ($inSingleQuote || $inDoubleQuote)) {
                $current .= $ch . ($sql[$i + 1] ?? '');
                $i += 2;
                continue;
            }

            // Track string state
            if ($ch === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($ch === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }

            // Statement delimiter (only outside strings)
            if ($ch === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            // Skip single-line comments
            if (!$inSingleQuote && !$inDoubleQuote && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol + 1;
                continue;
            }

            // Skip block comments
            if (!$inSingleQuote && !$inDoubleQuote && $ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Import CSV data into a table.
     * Returns [inserted, skipped, errors].
     */
    public function importCsv(string $database, string $table, string $csvContent, array $options = []): array
    {
        $delimiter  = $options['delimiter'] ?? ',';
        $enclosure  = $options['enclosure'] ?? '"';
        $hasHeader  = $options['has_header'] ?? true;
        $skipErrors = $options['skip_errors'] ?? true;

        $pdo = $this->connect($database);
        $lines = str_getcsv_rows($csvContent, $delimiter, $enclosure);

        if (empty($lines)) {
            return ['inserted' => 0, 'skipped' => 0, 'errors' => ['File is empty or unreadable.']];
        }

        // Get column names
        $columns = [];
        $startRow = 0;
        if ($hasHeader) {
            $columns = $lines[0];
            $startRow = 1;
        } else {
            // Use table column names
            $tableCols = $this->getColumns($database, $table);
            $columns = array_map(fn($c) => $c['Field'], $tableCols);
            // Limit to number of CSV columns
            $columns = array_slice($columns, 0, count($lines[0] ?? []));
        }

        if (empty($columns)) {
            return ['inserted' => 0, 'skipped' => 0, 'errors' => ['No columns detected.']];
        }

        // Build prepared statement
        $escapedCols = array_map(fn($c) => '`' . $this->escapeIdentifier(trim($c)) . '`', $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO `{$this->escapeIdentifier($table)}` (" . implode(',', $escapedCols) . ") VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);

        $inserted = 0;
        $skipped = 0;
        $errors = [];

        for ($i = $startRow; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) continue;

            // Pad or trim to match column count
            while (count($row) < count($columns)) $row[] = null;
            $row = array_slice($row, 0, count($columns));

            // Convert empty strings to null for nullable columns
            $row = array_map(fn($v) => ($v === '' || $v === 'NULL') ? null : $v, $row);

            try {
                $stmt->execute($row);
                $inserted++;
            } catch (\PDOException $e) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = "Row " . ($i + 1) . ": " . $e->getMessage();
                }
                if (!$skipErrors) break;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }
}

/**
 * Parse CSV content into rows (handles multiline fields properly).
 */
function str_getcsv_rows(string $content, string $delimiter = ',', string $enclosure = '"'): array
{
    $rows = [];
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    while (($row = fgetcsv($stream, 0, $delimiter, $enclosure)) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}
