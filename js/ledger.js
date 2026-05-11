/**
 * Ledger — Client-side JavaScript
 * Includes SQL syntax highlighting engine
 */

const Ledger = {

    // SQL Token Definitions

    SQL_KEYWORDS: new Set([
        // DML
        'SELECT','FROM','WHERE','AND','OR','NOT','IN','IS','NULL','AS','ON',
        'JOIN','INNER','LEFT','RIGHT','OUTER','CROSS','FULL','NATURAL','STRAIGHT_JOIN',
        'ORDER','BY','GROUP','HAVING','LIMIT','OFFSET','UNION','ALL','INTERSECT','EXCEPT',
        'INSERT','INTO','VALUES','VALUE','UPDATE','SET','DELETE','REPLACE','MERGE',
        'DISTINCT','DISTINCTROW','TOP','SQL_CALC_FOUND_ROWS','HIGH_PRIORITY','LOW_PRIORITY',
        'DELAYED','IGNORE','SQL_NO_CACHE','SQL_CACHE','FOR','SHARE','NOWAIT','SKIP','LOCKED',

        // DDL
        'CREATE','ALTER','DROP','TRUNCATE','RENAME','PURGE',
        'TABLE','DATABASE','SCHEMA','INDEX','VIEW','TRIGGER','PROCEDURE','FUNCTION','EVENT',
        'TABLESPACE','SERVER','LOGFILE','SEQUENCE',
        'IF','EXISTS','DEFAULT','AUTO_INCREMENT','PRIMARY','KEY',
        'FOREIGN','REFERENCES','UNIQUE','CHECK','CONSTRAINT','SPATIAL','FULLTEXT',
        'ADD','COLUMN','MODIFY','CHANGE','AFTER','FIRST','ALGORITHM','INPLACE','COPY',
        'GENERATED','ALWAYS','STORED','VIRTUAL','INVISIBLE','VISIBLE',

        // DCL / Admin
        'SHOW','DESCRIBE','DESC','EXPLAIN','ANALYZE','USE','GRANT','REVOKE',
        'FLUSH','RESET','OPTIMIZE','REPAIR','CHECK','CHECKSUM',
        'PREPARE','EXECUTE','DEALLOCATE','HANDLER',
        'LOAD','DATA','INFILE','OUTFILE','DUMPFILE','LINES','FIELDS','TERMINATED','ENCLOSED','ESCAPED',
        'KILL','SHUTDOWN',

        // Transaction
        'BEGIN','COMMIT','ROLLBACK','SAVEPOINT','TRANSACTION','START','RELEASE','WORK',
        'XA','CHAIN','NO',

        // Control flow
        'CASE','WHEN','THEN','ELSE','END','IF','ELSEIF','WHILE','DO','LOOP','REPEAT','UNTIL',
        'LEAVE','ITERATE','RETURN','SIGNAL','RESIGNAL','DECLARE','CONDITION','CURSOR','CONTINUE',
        'OPEN','FETCH','CLOSE',

        // Operators / predicates
        'LIKE','RLIKE','REGEXP','SOUNDS','BETWEEN','ANY','SOME','EXISTS',
        'ASC','FETCH','NEXT','ROWS','ONLY','PERCENT','PRECEDING','FOLLOWING','UNBOUNDED','CURRENT','ROW',
        'MATCH','AGAINST','BOOLEAN','MODE','EXPANSION',

        // Table / index options
        'WITH','RECURSIVE','TEMPORARY','TEMP',
        'LOCK','UNLOCK','TABLES','ENGINE','CHARSET','COLLATE','CHARACTER',
        'COMMENT','UNSIGNED','SIGNED','ZEROFILL',
        'CASCADE','RESTRICT','ACTION',
        'ROW_FORMAT','DYNAMIC','FIXED','COMPRESSED','REDUNDANT','COMPACT',
        'PARTITION','PARTITIONS','SUBPARTITION','RANGE','LIST','HASH','LINEAR',
        'STORAGE','DISK','MEMORY','USING','BTREE','HASH',
        'DEFINER','INVOKER','SECURITY','SQL','DETERMINISTIC','READS','MODIFIES','CONTAINS',
        'RETURNS','LANGUAGE','NO','EACH','BEFORE','AFTER','FOLLOWS','PRECEDES',

        // Misc
        'DUAL','OUTFILE','DUMPFILE','LOCAL','GLOBAL','SESSION','STATUS','VARIABLES',
        'PROCESSLIST','GRANTS','PRIVILEGES','WARNINGS','ERRORS','PROFILES',
        'BINARY','COLLATION','COLUMNS','DATABASES','ENGINES','EVENTS','TRIGGERS','PLUGINS',
        'MASTER','SLAVE','REPLICATION','RELAY','BINLOG','GTID',
        'IDENTIFIED','PASSWORD','REQUIRE','CIPHER','ISSUER','SUBJECT',
        'MAX_QUERIES_PER_HOUR','MAX_UPDATES_PER_HOUR','MAX_CONNECTIONS_PER_HOUR','MAX_USER_CONNECTIONS',
        'ENABLE','DISABLE','ON','COMPLETION','PRESERVE',
    ]),

    SQL_FUNCTIONS: new Set([
        // Aggregate
        'COUNT','SUM','AVG','MIN','MAX','BIT_AND','BIT_OR','BIT_XOR',
        'GROUP_CONCAT','JSON_ARRAYAGG','JSON_OBJECTAGG','STD','STDDEV','STDDEV_POP','STDDEV_SAMP',
        'VAR_POP','VAR_SAMP','VARIANCE',

        // Math
        'ABS','CEIL','CEILING','FLOOR','ROUND','TRUNCATE','MOD','POWER','POW','SQRT','EXP','LOG','LOG2','LOG10',
        'PI','RADIANS','DEGREES','SIN','COS','TAN','ASIN','ACOS','ATAN','ATAN2','COT',
        'RAND','SIGN','CRC32','CONV',

        // String
        'CONCAT','CONCAT_WS','SUBSTRING','SUBSTR','MID','TRIM','LTRIM','RTRIM',
        'UPPER','UCASE','LOWER','LCASE','LENGTH','CHAR_LENGTH','CHARACTER_LENGTH','OCTET_LENGTH','BIT_LENGTH',
        'REPLACE','REVERSE','REPEAT','SPACE','LPAD','RPAD','INSTR','LOCATE','POSITION','FIND_IN_SET',
        'FORMAT','FIELD','ELT','EXPORT_SET','MAKE_SET',
        'INSERT','LEFT','RIGHT','SUBSTRING_INDEX',
        'ASCII','ORD','CHAR','HEX','UNHEX','BIN','OCT',
        'QUOTE','SOUNDEX','WEIGHT_STRING','FROM_BASE64','TO_BASE64',
        'REGEXP_REPLACE','REGEXP_INSTR','REGEXP_LIKE','REGEXP_SUBSTR',

        // Date / Time
        'NOW','CURDATE','CURTIME','SYSDATE','UTC_DATE','UTC_TIME','UTC_TIMESTAMP','LOCALTIME','LOCALTIMESTAMP',
        'DATE','TIME','YEAR','MONTH','DAY','HOUR','MINUTE','SECOND','MICROSECOND',
        'DAYNAME','DAYOFMONTH','DAYOFWEEK','DAYOFYEAR','MONTHNAME','QUARTER','WEEK','WEEKDAY','WEEKOFYEAR','YEARWEEK',
        'DATEDIFF','TIMEDIFF','TIMESTAMPDIFF','TIMESTAMPADD',
        'DATE_ADD','DATE_SUB','ADDDATE','SUBDATE','ADDTIME','SUBTIME','PERIOD_ADD','PERIOD_DIFF',
        'DATE_FORMAT','TIME_FORMAT','STR_TO_DATE','GET_FORMAT',
        'MAKEDATE','MAKETIME','SEC_TO_TIME','TIME_TO_SEC','TO_DAYS','TO_SECONDS','FROM_DAYS',
        'LAST_DAY','EXTRACT',
        'TIMESTAMP','UNIX_TIMESTAMP','FROM_UNIXTIME',
        'CONVERT_TZ',
        'CURRENT_TIMESTAMP','CURRENT_DATE','CURRENT_TIME',

        // Control flow
        'IF','IFNULL','NULLIF','COALESCE','GREATEST','LEAST',
        'ISNULL','INTERVAL',

        // Cast / convert
        'CAST','CONVERT','BINARY',

        // JSON
        'JSON_EXTRACT','JSON_OBJECT','JSON_ARRAY','JSON_UNQUOTE','JSON_QUOTE',
        'JSON_SET','JSON_INSERT','JSON_REPLACE','JSON_REMOVE','JSON_MERGE','JSON_MERGE_PATCH','JSON_MERGE_PRESERVE',
        'JSON_CONTAINS','JSON_CONTAINS_PATH','JSON_DEPTH','JSON_LENGTH','JSON_TYPE','JSON_VALID',
        'JSON_KEYS','JSON_SEARCH','JSON_VALUE','JSON_TABLE','JSON_PRETTY','JSON_STORAGE_SIZE','JSON_STORAGE_FREE',

        // Window
        'ROW_NUMBER','RANK','DENSE_RANK','NTILE','PERCENT_RANK','CUME_DIST',
        'LAG','LEAD','FIRST_VALUE','LAST_VALUE','NTH_VALUE',
        'OVER','PARTITION','WINDOW',

        // Encryption / hash
        'MD5','SHA1','SHA2','SHA','AES_ENCRYPT','AES_DECRYPT','DES_ENCRYPT','DES_DECRYPT',
        'ENCRYPT','COMPRESS','UNCOMPRESS','UNCOMPRESSED_LENGTH',
        'RANDOM_BYTES','STATEMENT_DIGEST','STATEMENT_DIGEST_TEXT',

        // Info
        'VERSION','USER','CURRENT_USER','SYSTEM_USER','SESSION_USER',
        'DATABASE','SCHEMA','CONNECTION_ID','LAST_INSERT_ID',
        'FOUND_ROWS','ROW_COUNT','BENCHMARK','CHARSET','COERCIBILITY','COLLATION',

        // Misc
        'UUID','UUID_SHORT','UUID_TO_BIN','BIN_TO_UUID',
        'RAND','SLEEP','GET_LOCK','RELEASE_LOCK','IS_FREE_LOCK','IS_USED_LOCK',
        'INET_ATON','INET_NTOA','INET6_ATON','INET6_NTOA','IS_IPV4','IS_IPV6',
        'NAME_CONST','VALUES','DEFAULT',
        'ANY_VALUE','BIT_COUNT',

        // Geometry
        'ST_ASTEXT','ST_ASWKB','ST_GEOMFROMTEXT','ST_GEOMFROMWKB',
        'ST_DISTANCE','ST_WITHIN','ST_CONTAINS','ST_INTERSECTS','ST_BUFFER',
        'ST_AREA','ST_LENGTH','ST_CENTROID','ST_UNION','ST_SRID',
        'POINT','LINESTRING','POLYGON','MULTIPOINT','GEOMETRYCOLLECTION',
    ]),

    SQL_TYPES: new Set([
        // Numeric
        'INT','INTEGER','TINYINT','SMALLINT','MEDIUMINT','BIGINT',
        'FLOAT','DOUBLE','DECIMAL','NUMERIC','REAL','DEC','FIXED',
        'BIT','SERIAL',

        // String
        'CHAR','VARCHAR','TEXT','TINYTEXT','MEDIUMTEXT','LONGTEXT',
        'BLOB','TINYBLOB','MEDIUMBLOB','LONGBLOB',
        'BINARY','VARBINARY',
        'ENUM','SET',

        // Date / Time
        'DATE','DATETIME','TIMESTAMP','TIME','YEAR',

        // Other
        'BOOLEAN','BOOL','JSON',
        'GEOMETRY','POINT','LINESTRING','POLYGON','MULTIPOINT','MULTILINESTRING','MULTIPOLYGON','GEOMETRYCOLLECTION',
        'UUID',
    ]),

    SQL_CONSTANTS: new Set([
        'TRUE','FALSE','NULL',
        'CURRENT_TIMESTAMP','CURRENT_DATE','CURRENT_TIME','CURRENT_USER',
        'LOCALTIME','LOCALTIMESTAMP','UTC_DATE','UTC_TIME','UTC_TIMESTAMP',
        'PI','MAXVALUE',
    ]),

    SQL_OPERATORS: /^(!=|<>|>=|<=|:=|=>|\|\||&&|<<|>>|<=>|[+\-*/%&|^~<>=!])/,

    // Tokenizer

    /**
     * Tokenize SQL into an array of {type, value} objects.
     * Types: keyword, function, type, constant, string, number, comment, operator, identifier, whitespace
     */
    tokenize(sql) {
        const tokens = [];
        let i = 0;
        const len = sql.length;

        while (i < len) {
            const ch = sql[i];
            const rest = sql.substring(i);

            // Whitespace
            if (/\s/.test(ch)) {
                let j = i;
                while (j < len && /\s/.test(sql[j])) j++;
                tokens.push({ type: 'whitespace', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Block comment /* ... */
            if (ch === '/' && i + 1 < len && sql[i + 1] === '*') {
                let j = i + 2;
                while (j < len - 1 && !(sql[j] === '*' && sql[j + 1] === '/')) j++;
                j = Math.min(j + 2, len);
                tokens.push({ type: 'comment', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Line comment --
            if (ch === '-' && i + 1 < len && sql[i + 1] === '-') {
                let j = i + 2;
                while (j < len && sql[j] !== '\n') j++;
                tokens.push({ type: 'comment', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Line comment #
            if (ch === '#') {
                let j = i + 1;
                while (j < len && sql[j] !== '\n') j++;
                tokens.push({ type: 'comment', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Single-quoted string
            if (ch === "'") {
                let j = i + 1;
                let escaped = false;
                while (j < len) {
                    if (escaped) { escaped = false; j++; continue; }
                    if (sql[j] === '\\') { escaped = true; j++; continue; }
                    if (sql[j] === "'") {
                        if (j + 1 < len && sql[j + 1] === "'") { j += 2; continue; }
                        j++; break;
                    }
                    j++;
                }
                tokens.push({ type: 'string', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Double-quoted string
            if (ch === '"') {
                let j = i + 1;
                let escaped = false;
                while (j < len) {
                    if (escaped) { escaped = false; j++; continue; }
                    if (sql[j] === '\\') { escaped = true; j++; continue; }
                    if (sql[j] === '"') { j++; break; }
                    j++;
                }
                tokens.push({ type: 'string', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Backtick identifier
            if (ch === '`') {
                let j = i + 1;
                while (j < len && sql[j] !== '`') j++;
                if (j < len) j++;
                tokens.push({ type: 'backtick', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Variable @... or @@...
            if (ch === '@') {
                let j = i + 1;
                if (j < len && sql[j] === '@') j++;
                while (j < len && /[a-zA-Z0-9_]/.test(sql[j])) j++;
                tokens.push({ type: 'variable', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Numbers
            if (/[0-9]/.test(ch) || (ch === '.' && i + 1 < len && /[0-9]/.test(sql[i + 1]))) {
                let j = i;
                if (ch === '0' && j + 1 < len && (sql[j + 1] === 'x' || sql[j + 1] === 'X')) {
                    j += 2;
                    while (j < len && /[0-9a-fA-F]/.test(sql[j])) j++;
                } else {
                    while (j < len && /[0-9]/.test(sql[j])) j++;
                    if (j < len && sql[j] === '.') {
                        j++;
                        while (j < len && /[0-9]/.test(sql[j])) j++;
                    }
                    if (j < len && (sql[j] === 'e' || sql[j] === 'E')) {
                        j++;
                        if (j < len && (sql[j] === '+' || sql[j] === '-')) j++;
                        while (j < len && /[0-9]/.test(sql[j])) j++;
                    }
                }
                tokens.push({ type: 'number', value: sql.substring(i, j) });
                i = j;
                continue;
            }

            // Operators
            const opMatch = rest.match(this.SQL_OPERATORS);
            if (opMatch) {
                tokens.push({ type: 'operator', value: opMatch[0] });
                i += opMatch[0].length;
                continue;
            }

            // Punctuation
            if ('(),;.'.includes(ch)) {
                tokens.push({ type: 'punctuation', value: ch });
                i++;
                continue;
            }

            // Words
            if (/[a-zA-Z_]/.test(ch)) {
                let j = i;
                while (j < len && /[a-zA-Z0-9_]/.test(sql[j])) j++;
                const word = sql.substring(i, j);
                const upper = word.toUpperCase();

                // Look ahead for ( to detect function calls
                let la = j;
                while (la < len && sql[la] === ' ') la++;
                const isCall = la < len && sql[la] === '(';

                if (this.SQL_CONSTANTS.has(upper)) {
                    tokens.push({ type: 'constant', value: word });
                } else if (this.SQL_FUNCTIONS.has(upper) || (isCall && !this.SQL_KEYWORDS.has(upper))) {
                    tokens.push({ type: 'function', value: word });
                } else if (this.SQL_TYPES.has(upper)) {
                    tokens.push({ type: 'type', value: word });
                } else if (this.SQL_KEYWORDS.has(upper)) {
                    tokens.push({ type: 'keyword', value: word });
                } else {
                    tokens.push({ type: 'identifier', value: word });
                }
                i = j;
                continue;
            }

            tokens.push({ type: 'unknown', value: ch });
            i++;
        }

        return tokens;
    },

    // Table/Alias Resolution (Second Pass)

    /**
     * Scan tokens for FROM/JOIN/UPDATE/INTO/TABLE clauses,
     * extract table names and aliases, then re-classify matching
     * identifiers and backtick tokens as 'table' type.
     */
    resolveTableNames(tokens) {
        const tableNames = new Set();
        const aliases = new Map(); // alias → table name

        // Keywords that introduce a table name
        const TABLE_INTROS = new Set([
            'FROM','JOIN','INNER','LEFT','RIGHT','CROSS','FULL','NATURAL',
            'UPDATE','INTO','TABLE','TRUNCATE','DESCRIBE','DESC','EXPLAIN',
        ]);
        // Keywords that terminate a table-name scanning context
        const STOP_KEYWORDS = new Set([
            'WHERE','SET','ON','USING','ORDER','GROUP','HAVING','LIMIT',
            'VALUES','SELECT','UNION','INTO','LEFT','RIGHT','INNER','CROSS',
            'FULL','NATURAL','JOIN',
        ]);

        // Helper: get the raw name from an identifier or backtick token
        const getName = (t) => {
            if (!t) return null;
            if (t.type === 'identifier') return t.value;
            if (t.type === 'backtick') return t.value.replace(/`/g, '');
            return null;
        };

        // Helper: skip whitespace/comments, return next meaningful token index
        const nextReal = (from) => {
            let k = from;
            while (k < tokens.length && (tokens[k].type === 'whitespace' || tokens[k].type === 'comment')) k++;
            return k;
        };

        // Pass 1: Extract table names and aliases
        for (let i = 0; i < tokens.length; i++) {
            const t = tokens[i];
            if (t.type !== 'keyword') continue;
            const kw = t.value.toUpperCase();

            // Handle: FROM table1 [alias1], table2 [AS alias2], ...
            if (kw === 'FROM') {
                let j = nextReal(i + 1);
                while (j < tokens.length) {
                    const tbl = tokens[j];
                    const tblName = getName(tbl);
                    if (!tblName) break;
                    tableNames.add(tblName.toLowerCase());

                    // Look for alias or AS
                    let next = nextReal(j + 1);

                    // Skip schema dot: schema.table
                    if (next < tokens.length && tokens[next].type === 'punctuation' && tokens[next].value === '.') {
                        next = nextReal(next + 1); // actual table name after dot
                        const realTbl = getName(tokens[next]);
                        if (realTbl) tableNames.add(realTbl.toLowerCase());
                        next = nextReal(next + 1);
                    }

                    if (next < tokens.length && tokens[next].type === 'keyword' && tokens[next].value.toUpperCase() === 'AS') {
                        next = nextReal(next + 1);
                    }

                    // Check if next token is an alias (identifier, not a keyword)
                    if (next < tokens.length) {
                        const aliasToken = tokens[next];
                        const aliasName = getName(aliasToken);
                        if (aliasName && aliasToken.type === 'identifier' && !this.SQL_KEYWORDS.has(aliasName.toUpperCase())) {
                            aliases.set(aliasName.toLowerCase(), tblName.toLowerCase());
                            tableNames.add(aliasName.toLowerCase());
                            next = nextReal(next + 1);
                        }
                    }

                    // Check for comma (more tables in FROM list)
                    if (next < tokens.length && tokens[next].type === 'punctuation' && tokens[next].value === ',') {
                        j = nextReal(next + 1);
                        continue;
                    }
                    break;
                }
                continue;
            }

            // Handle: JOIN table [alias | AS alias]
            if (kw === 'JOIN') {
                let j = nextReal(i + 1);
                const tblName = getName(tokens[j]);
                if (tblName) {
                    tableNames.add(tblName.toLowerCase());
                    let next = nextReal(j + 1);

                    // Skip schema.table
                    if (next < tokens.length && tokens[next].type === 'punctuation' && tokens[next].value === '.') {
                        next = nextReal(next + 1);
                        const realTbl = getName(tokens[next]);
                        if (realTbl) tableNames.add(realTbl.toLowerCase());
                        next = nextReal(next + 1);
                    }

                    if (next < tokens.length && tokens[next].type === 'keyword' && tokens[next].value.toUpperCase() === 'AS') {
                        next = nextReal(next + 1);
                    }
                    if (next < tokens.length) {
                        const aliasName = getName(tokens[next]);
                        if (aliasName && tokens[next].type === 'identifier' && !this.SQL_KEYWORDS.has(aliasName.toUpperCase())) {
                            aliases.set(aliasName.toLowerCase(), tblName.toLowerCase());
                            tableNames.add(aliasName.toLowerCase());
                        }
                    }
                }
                continue;
            }

            // Handle: UPDATE table, INSERT INTO table, TABLE name, TRUNCATE table, DESCRIBE table
            if (['UPDATE','INTO','TABLE','TRUNCATE','DESCRIBE','DESC','EXPLAIN'].includes(kw)) {
                let j = nextReal(i + 1);
                const tblName = getName(tokens[j]);
                if (tblName) {
                    tableNames.add(tblName.toLowerCase());
                    // Check for schema.table
                    let next = nextReal(j + 1);
                    if (next < tokens.length && tokens[next].type === 'punctuation' && tokens[next].value === '.') {
                        next = nextReal(next + 1);
                        const realTbl = getName(tokens[next]);
                        if (realTbl) tableNames.add(realTbl.toLowerCase());
                    }
                }
                continue;
            }
        }

        // Pass 2: Re-classify tokens
        for (let i = 0; i < tokens.length; i++) {
            const t = tokens[i];

            // Re-classify identifiers that match known table/alias names
            if (t.type === 'identifier' && tableNames.has(t.value.toLowerCase())) {
                t.type = 'table';
            }
            // Re-classify backtick identifiers
            if (t.type === 'backtick') {
                const name = t.value.replace(/`/g, '').toLowerCase();
                if (tableNames.has(name)) {
                    t.type = 'table';
                }
            }

            // Dot-prefix detection: identifier followed by '.' is likely a table qualifier
            // even if we didn't catch it in the FROM scan
            if ((t.type === 'identifier') && i + 1 < tokens.length) {
                const next = tokens[i + 1];
                if (next.type === 'punctuation' && next.value === '.') {
                    // This identifier qualifies a column — mark as table
                    t.type = 'table';
                }
            }
        }

        return tokens;
    },

    // HTML Renderer

    /**
     * Convert token array to syntax-highlighted HTML string.
     */
    renderTokens(tokens) {
        let html = '';
        for (const token of tokens) {
            const escaped = this.escapeHtml(token.value);
            if (token.type === 'whitespace' || token.type === 'unknown') {
                html += escaped;
            } else {
                html += '<span class="sql-' + token.type + '">' + escaped + '</span>';
            }
        }
        return html;
    },

    escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    },

    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    },

    /**
     * Attach syntax highlighting + basic autocomplete to any textarea.
     * Creates a backdrop overlay behind the textarea for colored tokens.
     * Returns a cleanup function to remove listeners.
     */
    attachHighlighter(textarea) {
        if (!textarea || !this.tokenize) return () => {};

        // Wrap textarea in a container with a backdrop
        const wrapper = document.createElement('div');
        wrapper.className = 'mini-editor-wrap';
        textarea.parentNode.insertBefore(wrapper, textarea);
        wrapper.appendChild(textarea);

        const backdrop = document.createElement('div');
        backdrop.className = 'mini-editor-backdrop';
        const highlight = document.createElement('div');
        highlight.className = 'mini-editor-highlight';
        backdrop.appendChild(highlight);
        wrapper.insertBefore(backdrop, textarea);

        const self = this;

        function sync() {
            let tokens = self.tokenize(textarea.value);
            tokens = self.resolveTableNames(tokens);
            highlight.innerHTML = self.renderTokens(tokens) + '\n';
            backdrop.scrollTop = textarea.scrollTop;
            backdrop.scrollLeft = textarea.scrollLeft;
        }

        textarea.addEventListener('input', sync);
        textarea.addEventListener('scroll', function() {
            backdrop.scrollTop = textarea.scrollTop;
            backdrop.scrollLeft = textarea.scrollLeft;
        });

        // Initial sync
        sync();

        return function cleanup() {
            textarea.removeEventListener('input', sync);
        };
    },

    // Editor Sync

    syncEditor() {
        const editor = document.getElementById('sql-editor');
        const highlight = document.getElementById('editor-highlight');
        const backdrop = document.getElementById('editor-backdrop');
        const lineNumbers = document.getElementById('editor-line-numbers');

        if (!editor || !highlight) return;

        const value = editor.value;

        // Tokenize → resolve table names → render
        let tokens = this.tokenize(value);
        tokens = this.resolveTableNames(tokens);
        highlight.innerHTML = this.renderTokens(tokens) + '\n';

        // Sync scroll
        if (backdrop) {
            backdrop.scrollTop = editor.scrollTop;
            backdrop.scrollLeft = editor.scrollLeft;
        }

        // Update line numbers
        if (lineNumbers) {
            const lineCount = (value.match(/\n/g) || []).length + 1;
            let nums = '';
            for (let i = 1; i <= lineCount; i++) {
                nums += i + '\n';
            }
            lineNumbers.textContent = nums;
            lineNumbers.scrollTop = editor.scrollTop;
        }
    },

    // Autocomplete System

    // State
    acData: null,          // { databases, tables, columns, keywords }
    acVisible: false,
    acItems: [],
    acIndex: 0,
    acPrefix: '',
    acStart: 0,            // cursor offset where the current word starts

    /**
     * Fetch autocomplete data from the server
     */
    loadAutocompleteData() {
        const params = new URLSearchParams(window.location.search);
        const db = params.get('db') || '';
        fetch('ajax.php?action=autocomplete&db=' + encodeURIComponent(db))
            .then(r => r.json())
            .then(data => {
                if (data.error) { console.warn('AC load error:', data.error); return; }
                this.acData = data;
                this.setStatus('Autocomplete loaded: ' + (data.tables?.length || 0) + ' tables, ' + (data.columns?.length || 0) + ' columns');
            })
            .catch(e => console.warn('AC fetch failed:', e));
    },

    /**
     * Extract table names and aliases from a SQL query using the tokenizer
     * Returns: { tables: Set of lowercase names, aliasMap: Map alias→table }
     */
    extractQueryTables(sql) {
        const tokens = this.tokenize(sql);
        const tables = new Set();
        const aliasMap = new Map();
        const gn = (t) => { if(!t)return null; if(t.type==='identifier')return t.value; if(t.type==='backtick')return t.value.replace(/`/g,''); return null; };
        const nr = (from) => { let k=from; while(k<tokens.length&&(tokens[k].type==='whitespace'||tokens[k].type==='comment'))k++; return k; };

        for (let i = 0; i < tokens.length; i++) {
            if (tokens[i].type !== 'keyword') continue;
            const kw = tokens[i].value.toUpperCase();

            if (kw === 'FROM') {
                let j = nr(i+1);
                while (j < tokens.length) {
                    const tblName = gn(tokens[j]);
                    if (!tblName) break;
                    const tblLower = tblName.toLowerCase();
                    tables.add(tblLower);
                    let next = nr(j+1);
                    // schema.table
                    if (next<tokens.length && tokens[next].type==='punctuation' && tokens[next].value==='.') {
                        next=nr(next+1); const rt=gn(tokens[next]); if(rt){tables.delete(tblLower);tables.add(rt.toLowerCase());} next=nr(next+1);
                    }
                    if (next<tokens.length && tokens[next].type==='keyword' && tokens[next].value.toUpperCase()==='AS') next=nr(next+1);
                    if (next<tokens.length) {
                        const aliasName=gn(tokens[next]);
                        if(aliasName && tokens[next].type==='identifier' && !this.SQL_KEYWORDS.has(aliasName.toUpperCase())){
                            aliasMap.set(aliasName.toLowerCase(), tblLower);
                            tables.add(aliasName.toLowerCase());
                            next=nr(next+1);
                        }
                    }
                    if (next<tokens.length && tokens[next].type==='punctuation' && tokens[next].value===',') { j=nr(next+1); continue; }
                    break;
                }
            }

            if (kw === 'JOIN') {
                let j=nr(i+1); const tn=gn(tokens[j]);
                if(tn){
                    const tl=tn.toLowerCase(); tables.add(tl);
                    let next=nr(j+1);
                    if(next<tokens.length&&tokens[next].type==='punctuation'&&tokens[next].value==='.'){next=nr(next+1);const rt=gn(tokens[next]);if(rt){tables.delete(tl);tables.add(rt.toLowerCase());}next=nr(next+1);}
                    if(next<tokens.length&&tokens[next].type==='keyword'&&tokens[next].value.toUpperCase()==='AS')next=nr(next+1);
                    if(next<tokens.length){const an=gn(tokens[next]);if(an&&tokens[next].type==='identifier'&&!this.SQL_KEYWORDS.has(an.toUpperCase())){aliasMap.set(an.toLowerCase(),tl);tables.add(an.toLowerCase());}}
                }
            }

            if (['UPDATE','INTO'].includes(kw)) {
                let j=nr(i+1); const tn=gn(tokens[j]);
                if(tn) tables.add(tn.toLowerCase());
            }
        }

        return { tables, aliasMap };
    },

    /**
     * Resolve all alias names to real table names, returning a Set of real table names
     */
    resolveToRealTables(queryTables) {
        const real = new Set();
        for (const name of queryTables.tables) {
            if (queryTables.aliasMap.has(name)) {
                real.add(queryTables.aliasMap.get(name));
            } else {
                real.add(name);
            }
        }
        return real;
    },

    /**
     * Determine the SQL context at the cursor position
     */
    getContextAtCursor(text, cursorPos) {
        const before = text.substring(0, cursorPos);

        // Find the current word being typed
        const wordMatch = before.match(/[a-zA-Z0-9_`]*$/);
        const currentWord = wordMatch ? wordMatch[0].replace(/`/g, '') : '';
        const wordStart = cursorPos - (wordMatch ? wordMatch[0].length : 0);

        // Text before the current word (trimmed)
        const priorText = before.substring(0, wordStart).replace(/\s+$/, '').toUpperCase();

        // Extract tables/aliases from the full query for scoping
        const queryTables = this.extractQueryTables(text);
        const realTables = this.resolveToRealTables(queryTables);

        // Check for dot-prefix: table.col
        const dotMatch = before.match(/([a-zA-Z_][a-zA-Z0-9_]*)`?\.\s*([a-zA-Z0-9_]*)$/);
        if (dotMatch) {
            return {
                context: 'column_dot',
                prefix: dotMatch[2],
                tableHint: dotMatch[1],
                start: cursorPos - dotMatch[2].length,
                queryTables, realTables,
            };
        }

        // Determine context from preceding keyword
        const lastKeywordMatch = priorText.match(/(FROM|JOIN|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|CROSS\s+JOIN|FULL\s+JOIN|INTO|UPDATE|TABLE|TRUNCATE|DESCRIBE|DESC|EXPLAIN)\s*$/);
        if (lastKeywordMatch) {
            return { context: 'table', prefix: currentWord, start: wordStart, queryTables, realTables };
        }

        const useMatch = priorText.match(/USE\s*$/);
        if (useMatch) {
            return { context: 'database', prefix: currentWord, start: wordStart, queryTables, realTables };
        }

        // After SELECT, WHERE, ON, SET, ORDER BY, GROUP BY, HAVING → scoped columns
        const colContextMatch = priorText.match(/(SELECT|WHERE|AND|OR|ON|SET|ORDER\s+BY|GROUP\s+BY|HAVING|,)\s*$/);
        if (colContextMatch) {
            return { context: 'column', prefix: currentWord, start: wordStart, queryTables, realTables };
        }

        return { context: 'general', prefix: currentWord, start: wordStart, queryTables, realTables };
    },

    /**
     * Build the suggestions list based on context
     */
    buildSuggestions(ctx) {
        if (!this.acData) return [];
        const prefix = ctx.prefix.toLowerCase();
        let items = [];
        const realTables = ctx.realTables || new Set();
        const hasScope = realTables.size > 0;

        const match = (name) => prefix === '' || name.toLowerCase().startsWith(prefix);

        // Helper: check if a column belongs to one of the active query tables
        const inScope = (colTable) => !hasScope || realTables.has(colTable.toLowerCase());

        if (ctx.context === 'table') {
            // Suggest table names
            items = (this.acData.tables || [])
                .filter(t => match(t.name))
                .map(t => ({
                    label: t.name,
                    detail: t.engine + ' · ' + t.rows + ' rows',
                    type: 'table',
                    insert: '`' + t.name + '`',
                }));
        }

        else if (ctx.context === 'database') {
            items = (this.acData.databases || [])
                .filter(d => match(d))
                .map(d => ({
                    label: d,
                    detail: 'database',
                    type: 'database',
                    insert: '`' + d + '`',
                }));
        }

        else if (ctx.context === 'column_dot') {
            // Columns for a specific table or alias
            const hint = ctx.tableHint.toLowerCase();
            // Resolve alias → real table name
            let tableName = hint;
            if (ctx.queryTables && ctx.queryTables.aliasMap.has(hint)) {
                tableName = ctx.queryTables.aliasMap.get(hint);
            }
            items = (this.acData.columns || [])
                .filter(c => c.table.toLowerCase() === tableName && match(c.name))
                .map(c => ({
                    label: c.name,
                    detail: c.type + (c.key === 'PRI' ? ' · PK' : ''),
                    type: c.key === 'PRI' ? 'key' : 'column',
                    insert: c.name,
                }));
        }

        else if (ctx.context === 'column') {
            // Scoped columns: only from tables in FROM/JOIN/UPDATE
            const cols = (this.acData.columns || [])
                .filter(c => inScope(c.table) && match(c.name))
                .map(c => ({
                    label: c.name,
                    detail: c.table + ' · ' + c.type,
                    type: c.key === 'PRI' ? 'key' : 'column',
                    insert: c.name,
                }));
            // Deduplicate
            const seen = new Set();
            items = cols.filter(c => { if (seen.has(c.label)) return false; seen.add(c.label); return true; });

            // Also include table names already in scope (for table.col patterns)
            if (hasScope) {
                const scopedTbls = (this.acData.tables || [])
                    .filter(t => realTables.has(t.name.toLowerCase()) && match(t.name))
                    .map(t => ({
                        label: t.name,
                        detail: 'table · type table. for columns',
                        type: 'table',
                        insert: t.name + '.',
                    }));
                items = [...scopedTbls, ...items];
            } else {
                // No scope yet — show all tables + all columns
                const tbls = (this.acData.tables || [])
                    .filter(t => match(t.name))
                    .map(t => ({
                        label: t.name,
                        detail: 'table · ' + t.rows + ' rows',
                        type: 'table',
                        insert: t.name,
                    }));
                items = [...tbls, ...items];
            }
        }

        else {
            // General: keywords + tables + columns
            const kwList = [...this.SQL_KEYWORDS].filter(k => match(k)).slice(0, 8)
                .map(k => ({ label: k, detail: 'keyword', type: 'keyword', insert: k + ' ' }));
            const fnList = [...this.SQL_FUNCTIONS].filter(k => match(k)).slice(0, 5)
                .map(k => ({ label: k, detail: 'function', type: 'function', insert: k + '(' }));
            const tbls = (this.acData.tables || [])
                .filter(t => match(t.name))
                .map(t => ({ label: t.name, detail: 'table', type: 'table', insert: '`' + t.name + '`' }));
            items = [...tbls, ...kwList, ...fnList];
        }

        return items.slice(0, 15);
    },

    /**
     * Resolve an alias to a table name from tokens
     */
    _resolveAlias(tokens, alias) {
        const al = alias.toLowerCase();
        const gn = (t) => { if(!t) return null; if(t.type==='identifier'||t.type==='table') return t.value; if(t.type==='backtick') return t.value.replace(/`/g,''); return null; };
        const nr = (from) => { let k=from; while(k<tokens.length&&(tokens[k].type==='whitespace'||tokens[k].type==='comment'))k++; return k; };

        for (let i = 0; i < tokens.length; i++) {
            if (tokens[i].type !== 'keyword') continue;
            const kw = tokens[i].value.toUpperCase();
            if (!['FROM','JOIN'].includes(kw)) continue;

            let j = nr(i+1);
            while (j < tokens.length) {
                const tblName = gn(tokens[j]);
                if (!tblName) break;
                let next = nr(j+1);
                if (next<tokens.length && tokens[next].type==='punctuation' && tokens[next].value==='.') { next=nr(next+1); next=nr(next+1); }
                if (next<tokens.length && tokens[next].type==='keyword' && tokens[next].value.toUpperCase()==='AS') next=nr(next+1);
                if (next<tokens.length) {
                    const aliasName = gn(tokens[next]);
                    if (aliasName && !this.SQL_KEYWORDS.has(aliasName.toUpperCase())) {
                        if (aliasName.toLowerCase() === al) return tblName.toLowerCase();
                        next = nr(next+1);
                    }
                }
                if (next<tokens.length && tokens[next].type==='punctuation' && tokens[next].value===',') { j=nr(next+1); continue; }
                break;
            }
        }
        return null;
    },

    /**
     * Show the autocomplete dropdown
     */
    showAutocomplete() {
        const editor = document.getElementById('sql-editor');
        if (!editor || !this.acData) return;

        const ctx = this.getContextAtCursor(editor.value, editor.selectionStart);
        // Contexts that should show suggestions immediately (even with empty prefix)
        const immediateContexts = new Set(['column', 'column_dot', 'table', 'database']);
        if (ctx.prefix.length < 1 && !immediateContexts.has(ctx.context)) {
            this.hideAutocomplete();
            return;
        }

        const items = this.buildSuggestions(ctx);
        if (items.length === 0) {
            this.hideAutocomplete();
            return;
        }

        this.acItems = items;
        this.acPrefix = ctx.prefix;
        this.acStart = ctx.start;
        this.acIndex = 0;

        this.renderDropdown(editor);
        this.acVisible = true;
    },

    /**
     * Render the dropdown UI
     */
    renderDropdown(editor) {
        let dropdown = document.getElementById('ac-dropdown');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.id = 'ac-dropdown';
            dropdown.className = 'ac-dropdown';
            editor.parentElement.parentElement.appendChild(dropdown);
        }

        // Position: approximate from cursor
        const pos = this.getCaretCoordinates(editor);
        dropdown.style.left = pos.left + 'px';
        dropdown.style.top = pos.top + 'px';
        dropdown.style.display = 'block';

        // Render items
        dropdown.innerHTML = this.acItems.map((item, i) => {
            const iconMap = {
                table: '⊞', database: '⛁', column: '○', key: '🔑',
                keyword: '⌘', function: 'ƒ',
            };
            const icon = iconMap[item.type] || '·';
            const active = i === this.acIndex ? ' ac-item-active' : '';
            return `<div class="ac-item${active}" data-index="${i}">
                <span class="ac-icon ac-icon-${item.type}">${icon}</span>
                <span class="ac-label">${this.escapeHtml(item.label)}</span>
                <span class="ac-detail">${this.escapeHtml(item.detail)}</span>
            </div>`;
        }).join('');

        // Click handler
        dropdown.querySelectorAll('.ac-item').forEach(el => {
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.acIndex = parseInt(el.dataset.index);
                this.acceptAutocomplete();
            });
        });
    },

    /**
     * Approximate caret pixel coordinates in textarea
     */
    getCaretCoordinates(editor) {
        const mirror = document.createElement('div');
        const style = getComputedStyle(editor);
        const props = ['fontFamily','fontSize','fontWeight','lineHeight','letterSpacing',
            'wordSpacing','textIndent','whiteSpace','wordWrap','paddingLeft','paddingTop',
            'borderLeftWidth','borderTopWidth','tabSize'];
        props.forEach(p => mirror.style[p] = style[p]);
        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.wordWrap = 'break-word';
        mirror.style.width = editor.offsetWidth + 'px';
        mirror.style.overflow = 'hidden';

        const text = editor.value.substring(0, editor.selectionStart);
        const textNode = document.createTextNode(text);
        const span = document.createElement('span');
        span.textContent = '|';
        mirror.appendChild(textNode);
        mirror.appendChild(span);
        document.body.appendChild(mirror);

        const spanRect = span.offsetTop;
        const spanLeft = span.offsetLeft;
        document.body.removeChild(mirror);

        const containerRect = editor.parentElement.parentElement.getBoundingClientRect();
        const editorRect = editor.getBoundingClientRect();

        return {
            left: Math.min(spanLeft, editor.offsetWidth - 260),
            top: (spanRect - editor.scrollTop) + parseInt(style.lineHeight) + 4
        };
    },

    /**
     * Hide the dropdown
     */
    hideAutocomplete() {
        this.acVisible = false;
        const dropdown = document.getElementById('ac-dropdown');
        if (dropdown) dropdown.style.display = 'none';
    },

    /**
     * Accept the currently selected suggestion
     */
    acceptAutocomplete() {
        const editor = document.getElementById('sql-editor');
        if (!editor || !this.acVisible || this.acItems.length === 0) return;

        const item = this.acItems[this.acIndex];
        if (!item) return;

        const before = editor.value.substring(0, this.acStart);
        const after = editor.value.substring(editor.selectionStart);
        editor.value = before + item.insert + after;
        const newPos = this.acStart + item.insert.length;
        editor.selectionStart = editor.selectionEnd = newPos;

        this.hideAutocomplete();
        this.syncEditor();
        editor.focus();
    },

    /**
     * Handle keyboard events for autocomplete navigation
     */
    handleAutocompleteKey(e) {
        if (!this.acVisible) return false;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.acIndex = (this.acIndex + 1) % this.acItems.length;
            this.renderDropdown(document.getElementById('sql-editor'));
            return true;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.acIndex = (this.acIndex - 1 + this.acItems.length) % this.acItems.length;
            this.renderDropdown(document.getElementById('sql-editor'));
            return true;
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
            // Only accept if NOT Ctrl+Enter (which is execute)
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) return false;
            e.preventDefault();
            this.acceptAutocomplete();
            return true;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            this.hideAutocomplete();
            return true;
        }
        return false;
    },

    initAutocomplete() {
        const editor = document.getElementById('sql-editor');
        if (!editor) return;

        // Load data
        this.loadAutocompleteData();

        // Show autocomplete on input
        editor.addEventListener('input', (e) => {
            clearTimeout(this._acTimer);
            // Trigger immediately after space, period, or comma (context-switching chars)
            const lastChar = editor.value[editor.selectionStart - 1];
            if (lastChar === ' ' || lastChar === '.' || lastChar === ',') {
                this.showAutocomplete();
            } else {
                this._acTimer = setTimeout(() => this.showAutocomplete(), 50);
            }
        });

        // Also trigger on cursor movement (click somewhere in query)
        editor.addEventListener('click', () => {
            clearTimeout(this._acTimer);
            this._acTimer = setTimeout(() => this.showAutocomplete(), 100);
        });

        // Hide on blur (with delay for click handling)
        editor.addEventListener('blur', () => {
            setTimeout(() => this.hideAutocomplete(), 200);
        });

        // Navigate with arrows
        editor.addEventListener('keydown', (e) => {
            if (this.handleAutocompleteKey(e)) return;
        });
    },

    // Initialization

    // Modal Dialog System

    /**
     * Show a themed confirmation dialog. Returns a Promise<boolean>.
     * Usage: Ledger.confirm({ title, message, confirmText, cancelText, danger })
     */
    confirm(opts) {
        return new Promise((resolve) => {
            const o = Object.assign({
                title: 'Confirm',
                message: 'Are you sure?',
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                danger: false,
            }, opts);

            // Remove any existing modal
            this.closeModal();

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'ledger-modal';

            overlay.innerHTML = `
                <div class="modal-box">
                    <div class="modal-header">
                        <span class="modal-title">${this.escapeHtml(o.title)}</span>
                        <button class="modal-close" data-action="cancel">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(o.message)}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-ghost modal-btn" data-action="cancel">${this.escapeHtml(o.cancelText)}</button>
                        <button class="btn ${o.danger ? 'btn-danger' : 'btn-primary'} modal-btn" data-action="confirm">${this.escapeHtml(o.confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            // Animate in
            requestAnimationFrame(() => overlay.classList.add('modal-visible'));

            // Focus confirm button
            const confirmBtn = overlay.querySelector('[data-action="confirm"]');
            if (confirmBtn) confirmBtn.focus();

            const cleanup = (result) => {
                overlay.classList.remove('modal-visible');
                setTimeout(() => {
                    overlay.remove();
                    resolve(result);
                }, 150);
            };

            // Button clicks
            overlay.addEventListener('click', (e) => {
                const action = e.target.dataset?.action || e.target.closest('[data-action]')?.dataset?.action;
                if (action === 'confirm') cleanup(true);
                else if (action === 'cancel') cleanup(false);
                // Click on overlay background
                else if (e.target === overlay) cleanup(false);
            });

            // Keyboard
            const keyHandler = (e) => {
                if (e.key === 'Escape') { cleanup(false); document.removeEventListener('keydown', keyHandler); }
                if (e.key === 'Enter') { cleanup(true); document.removeEventListener('keydown', keyHandler); }
            };
            document.addEventListener('keydown', keyHandler);
        });
    },

    /**
     * Show a themed alert dialog (info only, single OK button). Returns Promise<void>.
     */
    alert(opts) {
        const o = typeof opts === 'string' ? { message: opts } : opts;
        return this.confirm({
            title: o.title || 'Notice',
            message: o.message || '',
            confirmText: o.okText || 'OK',
            cancelText: '',
            danger: o.danger || false,
        }).then(() => {});
    },

    closeModal() {
        const existing = document.getElementById('ledger-modal');
        if (existing) existing.remove();
    },

    init() {
        this.initHighlighter();
        this.initAutocomplete();
        this.initInlineEdit();
        this.initBulkSelect();
        this.initColTypeToggle();
        this.initCreateDatabase();
        this.initSidebar();
        this.initFavorites();
        this.bindKeyboard();
        this.bindQuickQueries();
        this.autoFocusEditor();
        this.initStatusMessages();
    },

    // Inline Cell Editing

    _editingCell: null,

    initInlineEdit() {
        const wrapper = document.getElementById('browse-table');
        if (!wrapper) return;

        const db    = wrapper.dataset.db;
        const table = wrapper.dataset.table;
        const pkCol = wrapper.dataset.pk;
        if (!pkCol) return; // No primary key = no editing

        // Click on editable cell → open editor
        wrapper.addEventListener('click', (e) => {
            const cell = e.target.closest('.cell-editable');
            if (!cell || cell.classList.contains('cell-editing')) return;
            this.openCellEditor(cell, db, table, pkCol);
        });

        // Delete buttons
        wrapper.querySelectorAll('.row-delete-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const pk = btn.dataset.pk;
                this.confirm({
                    title: 'Delete Row',
                    message: 'Delete row where ' + pkCol + ' = ' + pk + '? This cannot be undone.',
                    confirmText: 'Delete',
                    cancelText: 'Cancel',
                    danger: true,
                }).then(confirmed => {
                    if (confirmed) this.deleteRow(btn, db, table, pkCol, pk);
                });
            });
        });
    },

    openCellEditor(cell, db, table, pkCol) {
        // Close any existing editor
        if (this._editingCell) this.closeCellEditor(this._editingCell, false);

        const col     = cell.dataset.col;
        const isNull  = cell.dataset.null === '1';
        const value   = isNull ? '' : cell.dataset.value;
        const pkVal   = cell.closest('tr').dataset.pkVal;
        const origHtml = cell.innerHTML;

        cell.classList.add('cell-editing');
        cell.dataset.origHtml = origHtml;
        this._editingCell = cell;

        // Use textarea for large content (>60 chars or has newlines)
        const isLarge = value.length > 60 || value.includes('\n');
        const inputTag = isLarge
            ? `<textarea class="inline-input inline-input-large" placeholder="${isNull ? 'NULL' : ''}">${this.escapeHtml(value)}</textarea>`
            : `<input type="text" class="inline-input" value="${this.escapeHtml(value)}" placeholder="${isNull ? 'NULL' : ''}">`;

        // Build editor
        const editorHtml = `
            <div class="inline-editor${isLarge ? ' inline-editor-large' : ''}">
                ${inputTag}
                <div class="inline-actions">
                    <label class="inline-null-label">
                        <input type="checkbox" class="inline-null-check" ${isNull ? 'checked' : ''}>
                        <span>NULL</span>
                    </label>
                    <button class="btn btn-primary btn-sm inline-save" title="Save (Enter)">✓</button>
                    <button class="btn btn-ghost btn-sm inline-cancel" title="Cancel (Esc)">✕</button>
                </div>
            </div>
        `;
        cell.innerHTML = editorHtml;

        const input     = cell.querySelector('.inline-input');
        const nullCheck = cell.querySelector('.inline-null-check');
        const saveBtn   = cell.querySelector('.inline-save');
        const cancelBtn = cell.querySelector('.inline-cancel');

        // Auto-size textarea
        if (isLarge && input.tagName === 'TEXTAREA') {
            const autoSize = () => {
                input.style.height = 'auto';
                input.style.height = Math.min(Math.max(input.scrollHeight, 60), 300) + 'px';
            };
            autoSize();
            input.addEventListener('input', autoSize);
        }

        // Focus input
        input.focus();
        if (input.tagName === 'INPUT') input.select();

        // NULL checkbox toggles input
        nullCheck.addEventListener('change', () => {
            input.disabled = nullCheck.checked;
            if (nullCheck.checked) {
                input.value = '';
                input.placeholder = 'NULL';
            } else {
                input.disabled = false;
                input.focus();
            }
        });

        // Save
        const doSave = () => {
            const newNull = nullCheck.checked;
            const newVal  = input.value;
            this.saveCellValue(cell, db, table, pkCol, pkVal, col, newVal, newNull);
        };

        // Keyboard
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || input.tagName === 'INPUT')) { e.preventDefault(); doSave(); }
            if (e.key === 'Escape') { e.preventDefault(); this.closeCellEditor(cell, false); }
            if (e.key === 'Tab' && input.tagName === 'INPUT') {
                e.preventDefault();
                doSave();
                // Move to next editable cell
                setTimeout(() => {
                    const nextCell = cell.nextElementSibling?.classList?.contains('cell-editable')
                        ? cell.nextElementSibling
                        : cell.closest('tr').nextElementSibling?.querySelector('.cell-editable');
                    if (nextCell) nextCell.click();
                }, 100);
            }
            e.stopPropagation();
        });

        saveBtn.addEventListener('click', (e) => { e.stopPropagation(); doSave(); });
        cancelBtn.addEventListener('click', (e) => { e.stopPropagation(); this.closeCellEditor(cell, false); });
    },

    saveCellValue(cell, db, table, pkCol, pkVal, col, value, isNull) {
        cell.classList.add('cell-saving');

        const formData = new FormData();
        formData.append('action', 'update_cell');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('column', col);
        formData.append('value', value);
        formData.append('is_null', isNull ? '1' : '0');
        formData.append('pk_col', pkCol);
        formData.append('pk_val', pkVal);
        formData.append('_csrf_token', this.getCsrfToken());

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                cell.classList.remove('cell-saving');
                if (data.error) {
                    cell.classList.add('cell-error');
                    this.setStatus('Error: ' + data.error);
                    setTimeout(() => cell.classList.remove('cell-error'), 2000);
                    return;
                }
                // Update cell with new value
                cell.dataset.value = value;
                cell.dataset.null = isNull ? '1' : '0';
                this.closeCellEditor(cell, true, isNull ? null : value);
                cell.classList.add('cell-saved');
                setTimeout(() => cell.classList.remove('cell-saved'), 1500);
                this.setStatus('Cell updated: ' + col + ' = ' + (isNull ? 'NULL' : '"' + value + '"'));
            })
            .catch(err => {
                cell.classList.remove('cell-saving');
                cell.classList.add('cell-error');
                this.setStatus('Network error: ' + err.message);
                setTimeout(() => cell.classList.remove('cell-error'), 2000);
            });
    },

    closeCellEditor(cell, saved, newValue) {
        if (saved) {
            if (newValue === null) {
                cell.innerHTML = '<span class="cell-null">NULL</span>';
            } else {
                cell.textContent = newValue.length > 80 ? newValue.substring(0, 80) + '…' : newValue;
            }
        } else {
            cell.innerHTML = cell.dataset.origHtml || '';
        }
        cell.classList.remove('cell-editing', 'cell-saving');
        this._editingCell = null;
    },

    deleteRow(btn, db, table, pkCol, pkVal) {
        const row = btn.closest('tr');
        row.style.opacity = '0.4';

        const formData = new FormData();
        formData.append('action', 'delete_row');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('pk_col', pkCol);
        formData.append('pk_val', pkVal);
        formData.append('_csrf_token', this.getCsrfToken());

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    row.style.opacity = '1';
                    this.setStatus('Delete error: ' + data.error);
                    return;
                }
                row.style.transition = 'all 0.3s';
                row.style.opacity = '0';
                row.style.height = '0';
                setTimeout(() => row.remove(), 300);
                this.setStatus('Row deleted: ' + pkCol + ' = ' + pkVal);
                this.updateBulkBar();
            })
            .catch(err => {
                row.style.opacity = '1';
                this.setStatus('Network error: ' + err.message);
            });
    },

    // Bulk Row Selection

    initBulkSelect() {
        const wrapper = document.getElementById('browse-table');
        const selectAll = document.getElementById('select-all');
        const bulkBar = document.getElementById('bulk-bar');
        if (!wrapper || !selectAll || !bulkBar) return;

        const db    = wrapper.dataset.db;
        const table = wrapper.dataset.table;
        const pkCol = wrapper.dataset.pk;
        if (!pkCol) return;

        // Select all checkbox
        selectAll.addEventListener('change', () => {
            const checkboxes = wrapper.querySelectorAll('.row-select');
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
                cb.closest('tr').classList.toggle('row-selected', selectAll.checked);
            });
            this.updateBulkBar();
        });

        // Individual row checkboxes
        wrapper.addEventListener('change', (e) => {
            if (!e.target.classList.contains('row-select')) return;
            e.target.closest('tr').classList.toggle('row-selected', e.target.checked);

            // Update select-all state
            const all = wrapper.querySelectorAll('.row-select');
            const checked = wrapper.querySelectorAll('.row-select:checked');
            selectAll.checked = all.length > 0 && checked.length === all.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < all.length;

            this.updateBulkBar();
        });

        // Delete selected button
        document.getElementById('bulk-delete-btn').addEventListener('click', () => {
            const selected = this.getSelectedPks();
            if (selected.length === 0) return;

            this.confirm({
                title: 'Delete ' + selected.length + ' Row' + (selected.length > 1 ? 's' : ''),
                message: 'Delete ' + selected.length + ' selected row' + (selected.length > 1 ? 's' : '') + ' from ' + table + '? This cannot be undone.',
                confirmText: 'Delete ' + selected.length,
                cancelText: 'Cancel',
                danger: true,
            }).then(confirmed => {
                if (!confirmed) return;
                this.bulkDelete(db, table, pkCol, selected);
            });
        });

        // Clear selection button
        document.getElementById('bulk-clear-btn').addEventListener('click', () => {
            this.clearSelection();
        });
    },

    getSelectedPks() {
        const wrapper = document.getElementById('browse-table');
        if (!wrapper) return [];
        return Array.from(wrapper.querySelectorAll('.row-select:checked'))
            .map(cb => cb.dataset.pk);
    },

    clearSelection() {
        const wrapper = document.getElementById('browse-table');
        const selectAll = document.getElementById('select-all');
        if (!wrapper) return;
        wrapper.querySelectorAll('.row-select').forEach(cb => {
            cb.checked = false;
            cb.closest('tr').classList.remove('row-selected');
        });
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        this.updateBulkBar();
    },

    updateBulkBar() {
        const bulkBar = document.getElementById('bulk-bar');
        const countLabel = document.getElementById('bulk-count');
        if (!bulkBar || !countLabel) return;

        const selected = this.getSelectedPks();
        const count = selected.length;

        if (count > 0) {
            bulkBar.style.display = 'flex';
            countLabel.textContent = count + ' row' + (count > 1 ? 's' : '') + ' selected';
        } else {
            bulkBar.style.display = 'none';
        }
    },

    bulkDelete(db, table, pkCol, pkVals) {
        // Dim selected rows
        const wrapper = document.getElementById('browse-table');
        wrapper.querySelectorAll('.row-select:checked').forEach(cb => {
            cb.closest('tr').style.opacity = '0.4';
        });

        const formData = new FormData();
        formData.append('action', 'bulk_delete');
        formData.append('db', db);
        formData.append('table', table);
        formData.append('pk_col', pkCol);
        formData.append('pk_vals', JSON.stringify(pkVals));
        formData.append('_csrf_token', this.getCsrfToken());

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    wrapper.querySelectorAll('.row-select:checked').forEach(cb => {
                        cb.closest('tr').style.opacity = '1';
                    });
                    this.setStatus('Bulk delete error: ' + data.error);
                    return;
                }

                // Remove deleted rows with animation
                wrapper.querySelectorAll('.row-select:checked').forEach(cb => {
                    const row = cb.closest('tr');
                    row.style.transition = 'all 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                });

                this.clearSelection();
                this.setStatus('Deleted ' + data.affected + ' row' + (data.affected > 1 ? 's' : ''));
            })
            .catch(err => {
                wrapper.querySelectorAll('.row-select:checked').forEach(cb => {
                    cb.closest('tr').style.opacity = '1';
                });
                this.setStatus('Network error: ' + err.message);
            });
    },

    initHighlighter() {
        const editor = document.getElementById('sql-editor');
        if (!editor) return;

        // Persistent SQL drafts
        // Keyed by database so each DB keeps its own draft
        const dbName = new URLSearchParams(window.location.search).get('db') || '_global';
        const draftKey = 'ledger_draft_' + dbName;

        // Restore draft if editor is empty (no server-side value)
        if (!editor.value.trim()) {
            const saved = sessionStorage.getItem(draftKey);
            if (saved) {
                editor.value = saved;
            }
        }

        // Save on every input
        editor.addEventListener('input', () => {
            sessionStorage.setItem(draftKey, editor.value);
        });

        // Clear draft after successful execution (form submit)
        const form = document.getElementById('sql-form');
        if (form) {
            form.addEventListener('submit', () => {
                sessionStorage.removeItem(draftKey);
            });
        }

        // Initial highlight
        this.syncEditor();

        // Sync on every input
        editor.addEventListener('input', () => this.syncEditor());

        // Sync on scroll
        editor.addEventListener('scroll', () => {
            const backdrop = document.getElementById('editor-backdrop');
            const lineNumbers = document.getElementById('editor-line-numbers');
            if (backdrop) {
                backdrop.scrollTop = editor.scrollTop;
                backdrop.scrollLeft = editor.scrollLeft;
            }
            if (lineNumbers) {
                lineNumbers.scrollTop = editor.scrollTop;
            }
        });

        // Handle resize
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => {
                const backdrop = document.getElementById('editor-backdrop');
                if (backdrop) backdrop.style.height = editor.offsetHeight + 'px';
                const lineNumbers = document.getElementById('editor-line-numbers');
                if (lineNumbers) lineNumbers.style.height = editor.offsetHeight + 'px';
            }).observe(editor);
        }
    },

    // Theme Switching

    switchTheme(slug) {
        document.cookie = 'ledger_theme=' + slug + ';path=/;max-age=' + (365*86400) + ';SameSite=Lax';
        window.location.reload();
    },

    // Column Type Toggle

    toggleColTypes() {
        const table = document.getElementById('browse-table');
        if (!table) return;
        const hidden = table.classList.toggle('hide-col-types');
        document.cookie = 'ledger_hide_types=' + (hidden ? '1' : '0') + ';path=/;max-age=' + (365*86400) + ';SameSite=Lax';
        // Update button state
        const btn = document.getElementById('toggle-col-types');
        if (btn) {
            btn.classList.toggle('btn-toggled-off', hidden);
        }
    },

    initColTypeToggle() {
        const table = document.getElementById('browse-table');
        if (!table) return;
        const cookie = document.cookie.split(';').find(c => c.trim().startsWith('ledger_hide_types='));
        const show = cookie && cookie.trim().split('=')[1] === '0';
        if (!show) {
            table.classList.add('hide-col-types');
            const btn = document.getElementById('toggle-col-types');
            if (btn) btn.classList.add('btn-toggled-off');
        }
    },

    // Favorites

    initFavorites() {
        const self = this;
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.table-fav-btn, .browse-fav-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();

            const db = btn.dataset.db;
            const table = btn.dataset.table;
            if (!db || !table) return;

            // Disable briefly to prevent double-clicks
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'toggle_favorite');
            fd.append('db', db);
            fd.append('table', table);
            fd.append('_csrf_token', self.getCsrfToken());

            fetch('ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        self.setStatus('Error: ' + data.error);
                        btn.disabled = false;
                        return;
                    }
                    self.setStatus(data.favorited ? 'Added to favorites.' : 'Removed from favorites.');
                    // Reload so the sidebar rebuilds with the new favorites list
                    window.location.reload();
                })
                .catch(function(err) {
                    self.setStatus('Error: ' + err.message);
                    btn.disabled = false;
                });
        });
    },

    // Create / Drop Database

    initCreateDatabase() {
        const self = this;

        // Home page form toggle
        const homeBtn = document.getElementById('home-create-db-btn');
        const form = document.getElementById('create-db-form');
        const cancelBtn = document.getElementById('create-db-cancel');
        const submitBtn = document.getElementById('create-db-submit');

        if (homeBtn && form) {
            homeBtn.addEventListener('click', () => {
                form.style.display = '';
                homeBtn.style.display = 'none';
                document.getElementById('create-db-name').focus();
            });
        }
        if (cancelBtn && form) {
            cancelBtn.addEventListener('click', () => {
                form.style.display = 'none';
                if (homeBtn) homeBtn.style.display = '';
            });
        }
        if (submitBtn) {
            submitBtn.addEventListener('click', () => this.submitCreateDatabase());
        }

        // Sidebar "+" button — shows a prompt modal
        const sidebarBtn = document.getElementById('sidebar-create-db');
        if (sidebarBtn) {
            sidebarBtn.addEventListener('click', () => {
                // If the home form exists (we're on the home page), just toggle it
                if (form) {
                    form.style.display = '';
                    if (homeBtn) homeBtn.style.display = 'none';
                    document.getElementById('create-db-name').focus();
                    return;
                }
                // Otherwise use a simple prompt-style flow
                this.showCreateDbModal();
            });
        }

        // Drop database buttons
        document.querySelectorAll('.drop-db-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const dbName = btn.dataset.db;
                self.confirm({
                    title: 'Drop Database',
                    message: 'DROP DATABASE `' + dbName + '`? This will permanently delete all tables, data, and relationships. This cannot be undone.',
                    confirmText: 'Drop Database',
                    cancelText: 'Cancel',
                    danger: true,
                }).then(ok => {
                    if (!ok) return;
                    const formData = new FormData();
                    formData.append('action', 'drop_database');
                    formData.append('name', dbName);
                    formData.append('_csrf_token', self.getCsrfToken());

                    fetch('ajax.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            if (data.error) {
                                self.setStatus('Error: ' + data.error);
                                return;
                            }
                            self.setStatus('Database "' + dbName + '" dropped.');
                            window.location.href = '?';
                        });
                });
            });
        });

        // Enter key in create-db-name input
        const nameInput = document.getElementById('create-db-name');
        if (nameInput) {
            nameInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); this.submitCreateDatabase(); }
                if (e.key === 'Escape') { e.preventDefault(); if (cancelBtn) cancelBtn.click(); }
            });
        }
    },

    submitCreateDatabase() {
        const name = document.getElementById('create-db-name')?.value.trim();
        const charset = document.getElementById('create-db-charset')?.value || 'utf8mb4';
        const collation = document.getElementById('create-db-collation')?.value || 'utf8mb4_general_ci';

        if (!name) {
            this.setStatus('Database name is required.');
            return;
        }
        if (!/^[a-zA-Z0-9_\-]+$/.test(name)) {
            this.setStatus('Name can only contain letters, numbers, underscores, and hyphens.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'create_database');
        formData.append('name', name);
        formData.append('charset', charset);
        formData.append('collation', collation);
        formData.append('_csrf_token', this.getCsrfToken());

        fetch('ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    this.setStatus('Error: ' + data.error);
                    return;
                }
                this.setStatus('Database "' + name + '" created.');
                window.location.href = '?db=' + encodeURIComponent(name);
            })
            .catch(err => {
                this.setStatus('Network error: ' + err.message);
            });
    },

    showCreateDbModal() {
        this.closeModal();
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'ledger-modal';

        overlay.innerHTML = `
            <div class="modal-box" style="max-width:460px;">
                <div class="modal-header">
                    <span class="modal-title">Create Database</span>
                    <button class="modal-close" data-action="cancel">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="settings-field" style="margin-bottom:12px;">
                        <label class="settings-label">Database Name</label>
                        <input type="text" id="modal-db-name" class="settings-input" placeholder="my_database"
                               style="background:var(--bg-input);">
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="settings-field" style="flex:1;">
                            <label class="settings-label">Charset</label>
                            <select id="modal-db-charset" class="settings-input" style="background:var(--bg-input);">
                                <option value="utf8mb4" selected>utf8mb4</option>
                                <option value="utf8">utf8</option>
                                <option value="latin1">latin1</option>
                            </select>
                        </div>
                        <div class="settings-field" style="flex:1;">
                            <label class="settings-label">Collation</label>
                            <select id="modal-db-collation" class="settings-input" style="background:var(--bg-input);">
                                <option value="utf8mb4_general_ci" selected>utf8mb4_general_ci</option>
                                <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                                <option value="utf8mb4_bin">utf8mb4_bin</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost modal-btn" data-action="cancel">Cancel</button>
                    <button class="btn btn-primary modal-btn" id="modal-db-submit">Create</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('modal-visible'));

        const nameInput = overlay.querySelector('#modal-db-name');
        nameInput.focus();

        const doCreate = () => {
            const name = nameInput.value.trim();
            const charset = overlay.querySelector('#modal-db-charset').value;
            const collation = overlay.querySelector('#modal-db-collation').value;

            if (!name || !/^[a-zA-Z0-9_\-]+$/.test(name)) {
                this.setStatus('Invalid database name.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create_database');
            formData.append('name', name);
            formData.append('charset', charset);
            formData.append('collation', collation);
            formData.append('_csrf_token', this.getCsrfToken());

            fetch('ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        this.setStatus('Error: ' + data.error);
                        return;
                    }
                    overlay.remove();
                    window.location.href = '?db=' + encodeURIComponent(name);
                });
        };

        overlay.querySelector('#modal-db-submit').addEventListener('click', doCreate);
        nameInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') doCreate();
        });

        const close = () => {
            overlay.classList.remove('modal-visible');
            setTimeout(() => overlay.remove(), 150);
        };
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay || e.target.dataset?.action === 'cancel' || e.target.closest('[data-action="cancel"]')) close();
        });
        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', handler); }
        });
    },

    // Sidebar Settings

    initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const gearBtn = document.getElementById('sidebar-gear');
        const panel = document.getElementById('sidebar-settings');
        if (!sidebar || !gearBtn || !panel) return;

        const setCookie = (name, val) => {
            document.cookie = name + '=' + val + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax';
        };

        // Chevron toggle — collapse/expand table list without page reload
        sidebar.querySelectorAll('.db-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const group = btn.closest('.db-group');
                const tableList = group.querySelector('.table-list');
                if (!tableList) return;

                const isCollapsed = tableList.style.display === 'none';
                tableList.style.display = isCollapsed ? '' : 'none';

                // Swap icon
                const svgDown = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>';
                const svgRight = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>';
                btn.innerHTML = isCollapsed ? svgDown : svgRight;
                btn.classList.toggle('open', isCollapsed);
                btn.title = isCollapsed ? 'Collapse' : 'Expand';
            });
        });

        // Toggle settings panel
        gearBtn.addEventListener('click', () => {
            panel.style.display = panel.style.display === 'none' ? '' : 'none';
        });

        // Spacing buttons
        const currentSpacing = sidebar.dataset.spacing || 'normal';
        panel.querySelectorAll('.sidebar-spacing-btn').forEach(btn => {
            if (btn.dataset.spacing === currentSpacing) btn.classList.add('active');
            btn.addEventListener('click', () => {
                panel.querySelectorAll('.sidebar-spacing-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                sidebar.dataset.spacing = btn.dataset.spacing;
                setCookie('ledger_sidebar_spacing', btn.dataset.spacing);
            });
        });

        // Hide counts checkbox
        const hideCounts = document.getElementById('sidebar-hide-counts');
        if (hideCounts) {
            hideCounts.checked = sidebar.dataset.hideCounts === '1';
            hideCounts.addEventListener('change', () => {
                sidebar.dataset.hideCounts = hideCounts.checked ? '1' : '0';
                setCookie('ledger_hide_counts', hideCounts.checked ? '1' : '0');
            });
        }

        // Table filter
        const filterInput = document.getElementById('sidebar-filter');
        if (filterInput) {
            filterInput.addEventListener('input', () => {
                const term = filterInput.value.toLowerCase().trim();

                // Filter table rows inside expanded db groups
                sidebar.querySelectorAll('.table-row').forEach(row => {
                    const name = row.querySelector('.table-name');
                    if (!name) return;
                    const text = name.textContent.toLowerCase();
                    const match = !term || text.includes(term);
                    row.style.display = match ? '' : 'none';

                    // Highlight matching text
                    if (term && match) {
                        const orig = name.textContent;
                        const idx = text.indexOf(term);
                        name.innerHTML =
                            escapeHtml(orig.substring(0, idx)) +
                            '<mark class="sidebar-match">' + escapeHtml(orig.substring(idx, idx + term.length)) + '</mark>' +
                            escapeHtml(orig.substring(idx + term.length));
                    } else {
                        // Restore plain text (remove mark tags)
                        if (name.querySelector('mark')) {
                            name.textContent = name.textContent;
                        }
                    }
                });

                // Also filter favorites
                sidebar.querySelectorAll('.fav-item').forEach(item => {
                    const name = item.querySelector('.fav-item-table');
                    if (!name) return;
                    const text = name.textContent.toLowerCase();
                    item.style.display = (!term || text.includes(term)) ? '' : 'none';
                });

                // Show/hide empty db groups (all tables hidden)
                sidebar.querySelectorAll('.db-group').forEach(group => {
                    const tableList = group.querySelector('.table-list');
                    if (!tableList) return;
                    const visibleRows = tableList.querySelectorAll('.table-row:not([style*="display: none"])');
                    // If filtering and no visible tables, dim the db group
                    if (term) {
                        group.style.opacity = visibleRows.length ? '' : '0.3';
                    } else {
                        group.style.opacity = '';
                    }
                });

                // Show/hide fav section header
                const favSection = sidebar.querySelector('.fav-section');
                if (favSection && term) {
                    const visibleFavs = favSection.querySelectorAll('.fav-item:not([style*="display: none"])');
                    favSection.style.display = visibleFavs.length ? '' : 'none';
                } else if (favSection) {
                    favSection.style.display = '';
                }
            });

            // Ctrl+F or / to focus filter
            document.addEventListener('keydown', (e) => {
                if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) &&
                    !document.activeElement.isContentEditable) {
                    e.preventDefault();
                    filterInput.focus();
                    filterInput.select();
                }
            });

            function escapeHtml(s) {
                return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }
        }
    },

    // Keyboard Shortcuts

    bindKeyboard() {
        const editor = document.getElementById('sql-editor');
        const form = document.getElementById('sql-form');

        if (editor && form) {
            editor.addEventListener('keydown', (e) => {
                // Autocomplete gets first priority
                if (this.acVisible) {
                    if (this.handleAutocompleteKey(e)) return;
                }

                // Ctrl+Enter → Execute
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    this.hideAutocomplete();
                    form.submit();
                    return;
                }

                // Tab → 4 spaces (only when AC is NOT visible)
                if (e.key === 'Tab' && !e.shiftKey) {
                    e.preventDefault();
                    const start = editor.selectionStart;
                    const end = editor.selectionEnd;
                    editor.value = editor.value.substring(0, start) + '    ' + editor.value.substring(end);
                    editor.selectionStart = editor.selectionEnd = start + 4;
                    this.syncEditor();
                }

                // Shift+Tab → Remove indent
                if (e.key === 'Tab' && e.shiftKey) {
                    e.preventDefault();
                    const start = editor.selectionStart;
                    const before = editor.value.substring(0, start);
                    const lineStart = before.lastIndexOf('\n') + 1;
                    const linePrefix = editor.value.substring(lineStart, start);
                    const spaces = linePrefix.match(/^ {1,4}/);
                    if (spaces) {
                        editor.value = editor.value.substring(0, lineStart) + editor.value.substring(lineStart + spaces[0].length);
                        editor.selectionStart = editor.selectionEnd = start - spaces[0].length;
                        this.syncEditor();
                    }
                }

                // Auto-close quotes and brackets
                const pairs = { "'": "'", '"': '"', '(': ')', '`': '`' };
                if (pairs[e.key] && editor.selectionStart === editor.selectionEnd) {
                    const pos = editor.selectionStart;
                    const after = editor.value[pos];
                    if (!after || !/[a-zA-Z0-9]/.test(after)) {
                        e.preventDefault();
                        const closing = pairs[e.key];
                        editor.value = editor.value.substring(0, pos) + e.key + closing + editor.value.substring(pos);
                        editor.selectionStart = editor.selectionEnd = pos + 1;
                        this.syncEditor();
                    }
                }
            });
        }

        // Global: Ctrl+Shift+S → Focus SQL
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'S' || e.key === 's')) {
                e.preventDefault();
                if (editor) {
                    editor.focus();
                } else {
                    const params = new URLSearchParams(window.location.search);
                    params.set('tab', 'sql');
                    window.location.search = params.toString();
                }
            }

            // ? → Shortcut overlay (only when not typing in an input)
            if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                const tag = (e.target.tagName || '').toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;
                e.preventDefault();
                this.showShortcutOverlay();
            }

            // Escape → close shortcut overlay
            if (e.key === 'Escape') {
                const overlay = document.getElementById('shortcut-overlay');
                if (overlay) { overlay.remove(); e.preventDefault(); }
            }
        });
    },

    // Shortcut Overlay

    showShortcutOverlay() {
        if (document.getElementById('shortcut-overlay')) {
            document.getElementById('shortcut-overlay').remove();
            return;
        }

        const shortcuts = [
            { section: 'General', items: [
                ['?', 'Show this overlay'],
                ['Esc', 'Close overlay / cancel edit / dismiss modal'],
                ['Ctrl + Shift + S', 'Focus SQL editor'],
            ]},
            { section: 'SQL Editor', items: [
                ['Ctrl + Enter', 'Execute query'],
                ['Tab', 'Insert 4 spaces'],
                ['Shift + Tab', 'Remove indent'],
                ['↑ ↓', 'Navigate autocomplete'],
                ['Tab / Enter', 'Accept autocomplete suggestion'],
                ['Esc', 'Close autocomplete'],
            ]},
            { section: 'Data Browsing', items: [
                ['Click cell', 'Edit inline'],
                ['Enter', 'Save cell edit'],
                ['Tab', 'Save & edit next cell'],
                ['Esc', 'Cancel cell edit'],
                ['Ctrl + Enter', 'Save textarea edit'],
            ]},
            { section: 'Modals & Forms', items: [
                ['Enter', 'Confirm / submit'],
                ['Esc', 'Cancel / close'],
            ]},
        ];

        const overlay = document.createElement('div');
        overlay.id = 'shortcut-overlay';
        overlay.className = 'shortcut-overlay';

        let html = '<div class="shortcut-box">';
        html += '<div class="shortcut-header">';
        html += '<span class="shortcut-title">Keyboard Shortcuts</span>';
        html += '<button class="shortcut-close" id="shortcut-close">&times;</button>';
        html += '</div>';
        html += '<div class="shortcut-body">';

        shortcuts.forEach(group => {
            html += '<div class="shortcut-section">';
            html += '<div class="shortcut-section-title">' + group.section + '</div>';
            group.items.forEach(item => {
                html += '<div class="shortcut-row">';
                html += '<span class="shortcut-keys">';
                item[0].split(' + ').forEach((k, i) => {
                    if (i > 0) html += ' <span class="shortcut-plus">+</span> ';
                    // Handle special display for arrow keys etc
                    if (k === '/' || k === '↑ ↓') {
                        html += '<kbd>' + k + '</kbd>';
                    } else {
                        html += '<kbd>' + k + '</kbd>';
                    }
                });
                html += '</span>';
                html += '<span class="shortcut-desc">' + item[1] + '</span>';
                html += '</div>';
            });
            html += '</div>';
        });

        html += '</div>';
        html += '<div class="shortcut-footer">Press <kbd>?</kbd> or <kbd>Esc</kbd> to close</div>';
        html += '</div>';

        overlay.innerHTML = html;
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('shortcut-visible'));

        // Close handlers
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay || e.target.id === 'shortcut-close') overlay.remove();
        });
    },

    // Quick Query Buttons

    bindQuickQueries() {
        document.querySelectorAll('.quick-query[data-sql]').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                this.loadQuery(el.dataset.sql);
            });
        });
    },

    loadQuery(sql) {
        const editor = document.getElementById('sql-editor');
        if (editor) {
            editor.value = sql;
            editor.focus();
            this.syncEditor();
            this.setStatus('Query loaded — press Ctrl+Enter to execute');
        }
    },

    loadAndRun(sql) {
        const editor = document.getElementById('sql-editor');
        const form = document.getElementById('sql-form');
        if (editor && form) {
            editor.value = sql;
            this.syncEditor();
            form.submit();
        }
    },

    // Auto Focus

    autoFocusEditor() {
        const editor = document.getElementById('sql-editor');
        const params = new URLSearchParams(window.location.search);
        if (params.get('tab') === 'sql' && editor) {
            editor.focus();
            editor.selectionStart = editor.selectionEnd = editor.value.length;
        }
    },

    // Status Bar

    setStatus(msg) {
        const el = document.getElementById('status-message');
        if (el) el.textContent = msg;
    },

    initStatusMessages() {
        const params = new URLSearchParams(window.location.search);
        const db = params.get('db');
        const table = params.get('table');
        if (db && table) this.setStatus('Browsing ' + db + '.' + table);
        else if (db) this.setStatus('Database: ' + db);
        else this.setStatus('Ready — select a database');

        const resultMeta = document.querySelector('.result-meta');
        if (resultMeta) this.setStatus(resultMeta.textContent.trim());
        const errorBox = document.querySelector('.error-box');
        if (errorBox && params.get('tab') === 'sql') this.setStatus('Query error — see details above');
        const successBox = document.querySelector('.success-box');
        if (successBox) this.setStatus(successBox.textContent.trim());
    },
};

document.addEventListener('DOMContentLoaded', () => Ledger.init());
