<p align="center">
  <img src="https://raw.githubusercontent.com/ClearanceClarence/Ledger/refs/heads/main/assets/logo.svg" alt="Ledger" width="540">
</p>

<p align="center">
  <strong>The database tool phpMyAdmin should have been.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Version-1.0.2--beta-4ade80?style=flat-square" alt="Version 1.0.2-beta">
  <img src="https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7+-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/MariaDB-10.3+-003545?style=flat-square&logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License">
  <img src="https://img.shields.io/badge/Dependencies-Zero-orange?style=flat-square" alt="Zero Dependencies">
</p>

<p align="center">
  <em>Drop a folder into your web server. Run the installer. Manage your databases.<br>No Composer. No npm. No build step. No framework. Just PHP.</em>
</p>

<br>

<p align="center">
  <a href="#-why-ledger">Why Ledger?</a> · 
  <a href="#-get-started">Get Started</a> · 
  <a href="#-docker">Docker</a> · 
  <a href="#-sql-editor">SQL Editor</a> · 
  <a href="#-data-browsing--editing">Browse & Edit</a> · 
  <a href="#-search-across-tables">Search</a> · 
  <a href="#-er-diagram">ER Diagram</a> · 
  <a href="#-views-triggers-routines--events">Views, Triggers, Routines & Events</a> · 
  <a href="#-saved-queries">Saved Queries</a> · 
  <a href="#-processes">Processes</a> · 
  <a href="#-table-management">Tables</a> · 
  <a href="#-import--export">Import/Export</a> · 
  <a href="#-security--2fa">Security & 2FA</a> · 
  <a href="#-theming--fonts">Themes</a> · 
  <a href="CHANGELOG.md">Changelog</a>
</p>

---

## 💡 Why Ledger?

phpMyAdmin ships with every shared host and XAMPP stack on the planet, but its interface hasn't meaningfully changed since 2003. The SQL editor is a `<textarea>` with coloring. Editing a row reloads the entire page. Searching means opening each table one by one. There's no way to visualize relationships. No query history. The security defaults are weak enough that the official docs tell you to restrict access at the web server level.

Ledger is a ground-up replacement that keeps the one thing phpMyAdmin gets right — drop a folder, open a browser, manage your databases — and fixes everything else.

| | phpMyAdmin | Ledger |
|:-|:-----------|:--------|
| **Install** | Download, extract, edit `config.inc.php`, set blowfish secret | Drop folder → open browser → 3-step visual wizard |
| **SQL Editor** | Textarea with syntax coloring | Custom tokenizer (612 tokens), context-aware autocomplete, EXPLAIN visualizer |
| **Edit Data** | Click Edit → full page form → submit → redirect back | Click any cell → type → Enter. AJAX save. Page never reloads |
| **FK Navigation** | Manual: note the ID, switch tables, search | Click FK value → jump to referenced row. Automatic linking |
| **Views** | Basic listing | Full CRUD with syntax-highlighted definitions, sidebar integration |
| **Triggers** | Basic listing | Create, edit (drop+recreate with rollback), drop. Color-coded badges |
| **Routines** | Basic listing | Full CRUD for stored procedures and functions. Syntax-highlighted editor with skeleton templates |
| **Events** | Basic listing | Full CRUD with schedule display, one-click enable/disable, scheduler status warning when `event_scheduler = OFF` |
| **Search** | Search within one table at a time | Search across every table in the database simultaneously |
| **ER Diagram** | Not available | Interactive SVG with drag, zoom, auto-layout, crow's foot notation, save/load per database |
| **Query History** | Not available | Session-stored with syntax highlighting, search, expand-on-click detail with error messages and result summaries, per-entry load/delete, tinted rows |
| **Saved Queries** | Bookmarks with limited UI | Save, rename, delete, organize. Click to load. Per-user storage |
| **Table Ops** | Scattered across Operations, Structure, and SQL tabs | Dedicated Operations tab + overview row actions. Favorites system |
| **Maintenance** | Optimize only in some views | Optimize, Analyze, Check, Repair — all in one panel with inline results |
| **Processes** | Separate Status → Processes tab, requires refresh | Live processlist with 2/5/10s auto-refresh, color-scaled query time, kill button, filter, current-session marker |
| **Partitions** | View only | Full management: create, add, drop, truncate, optimize, rebuild, remove all |
| **Import** | Standard form | Drag-and-drop SQL/CSV with syntax-highlighted preview |
| **Auth** | HTTP Basic or cookie-based with manual config | Bcrypt + optional TOTP 2FA, brute force lockout, CSRF, IP whitelist |
| **Themes** | 3 color schemes | 20 polished themes (10 light, 10 dark) + custom theme API + per-zone font control |
| **Docker** | Not included | `docker-compose up` — Ledger + MySQL running in seconds |
| **Dependencies** | Requires Composer on v5.2+ | Zero. Pure PHP + vanilla JS. Entire codebase is in the repo |

---

## 🚀 Get Started

```bash
git clone https://github.com/ClearanceClarence/Ledger.git
```

Copy the `ledger/` folder into your web root (`htdocs/`, `www/`, `public_html/`) and open it in a browser.

The **first-run installer** walks you through three steps:

1. **Database connection** — enter host, port, username, password. Ledger tests the connection before proceeding. If it fails, you see the exact PDO error with suggestions.
2. **Admin account** — choose a username and password. Passwords are bcrypt-hashed immediately. Minimum 6 characters. Common passwords like `password`, `123456`, `admin` are rejected. No default credentials are ever written to disk.
3. **Done** — `config.php` is generated and you're redirected to the login page.

Works out of the box with **XAMPP**, **WAMP**, **MAMP**, **Laragon**, **DDEV**, **Herd**, or any Apache + PHP + MySQL/MariaDB stack. No `.env` files, no Composer, no npm, no build tools.

---

## 🐳 Docker

Run Ledger with MySQL in one command:

```bash
git clone https://github.com/ClearanceClarence/Ledger.git
cd Ledger
docker-compose up -d
```

Open `http://localhost:8080/ledger/` and use host `db`, password `ledger_root_pass` in the installer. MySQL is exposed on port `3307` for external tools. Three named volumes persist data across restarts. See [DOCKER.md](DOCKER.md) for full details.

---

## ✏️ SQL Editor

Not a `<textarea>` with color. A real code editor built from scratch in vanilla JavaScript.

### Tokenizer

The tokenizer recognizes **612 tokens** across 4 categories:

| Category | Count | Examples |
|:---------|:------|:--------|
| **Keywords** | 286 | `SELECT`, `LEFT JOIN`, `PARTITION BY`, `SQL_CALC_FOUND_ROWS`, `STRAIGHT_JOIN`, `FOR UPDATE NOWAIT`, `LOAD DATA INFILE`, `XA COMMIT` |
| **Functions** | 266 | `COUNT()`, `JSON_TABLE()`, `REGEXP_REPLACE()`, `ST_DISTANCE()`, `UUID_TO_BIN()`, `CUME_DIST()`, `AES_ENCRYPT()` |
| **Types** | 46 | `INT`, `VARCHAR`, `JSON`, `GEOMETRY`, `MULTIPOLYGON`, `SERIAL` |
| **Constants** | 14 | `TRUE`, `FALSE`, `NULL`, `CURRENT_TIMESTAMP`, `MAXVALUE`, `PI` |

Each token type gets its own color in every theme. The tokenizer handles single-quoted strings, double-quoted strings, backtick identifiers, single-line comments (`--`), block comments (`/* */`), hex literals (`0xFF`), binary literals (`0b1010`), session variables (`@@`), and user variables (`@var`).

### Autocomplete

Context-aware, not just a word list:

- Type `FROM ` → suggests all table names in the current database
- Type `WHERE u.` → suggests columns from the table aliased as `u`
- Type `JOIN orders ` → suggests `ON` and available columns
- Type `GROUP BY ` → suggests columns from tables already in the query

The system uses a two-pass approach: first pass extracts all table references and aliases from `FROM`, `JOIN`, `INTO`, and `UPDATE` clauses. Second pass re-classifies matching identifiers throughout the entire query, so `u` in `WHERE u.active = 1` gets highlighted as a table reference when there's a `FROM users u` elsewhere in the query.

### Editor Features

- Real-time syntax highlighting via a transparent `<textarea>` overlaid on a `<pre>` backdrop
- Line numbers with scroll sync
- `Ctrl+Enter` to execute
- `Tab` inserts 4 spaces, `Shift+Tab` removes indent
- Auto-closing for quotes (`'`, `"`), backticks, and parentheses
- Query results displayed inline: SELECT results as a data table, write operations as "N rows affected"
- Error display with MySQL error code
- **EXPLAIN button** — one-click query plan analysis. Results rendered in a dedicated panel with color-coded access types (green: const/eq_ref, blue: ref/range, amber: index, red: ALL), warning badges on Extra notes (filesort, temporary), row count highlighting, and a legend. Works on selected text or the full editor content
- **Persistent drafts** — editor content auto-saves to `sessionStorage` per database. Navigate away and come back — your query is still there. Cleared after successful execution

---

## 📊 Data Browsing & Editing

### Browse

Select a table and you see a paginated data grid with:

- Column headers with click-to-sort (ASC/DESC toggle)
- Type-aware cell styling: NULL values dimmed, numbers right-aligned, dates formatted, hashes truncated
- Search bar that filters across all columns with `LIKE '%term%'`
- Pagination controls with page numbers and row counts
- A **syntax-highlighted query bar** showing the exact SQL being executed with an "Edit" link that opens it in the SQL tab
- **FK drill-down** — columns with foreign keys display values as clickable links. Click to jump to the referenced row in the target table with an exact-match filter. A blue banner shows the active filter with a "Clear" button
- **Row detail panel** — click the eye button (👁) on any row to open a slide-out panel showing every column vertically with full untruncated values, column types, PK/FK badges, and FK drill-down links
- **Sidebar table filter** — type `/` or use the filter input to search tables by name with match highlighting
- **Favorites** — star tables from the sidebar, browse toolbar, or All Tables overview. Starred tables appear in a dedicated section at the top of the sidebar

### Inline Editing

Click any cell to edit it in place:

- Single-line `<input>` for short values
- Auto-switching to a resizable `<textarea>` for content over 60 characters or containing newlines (auto-grows between 60–300px)
- `Enter` saves via AJAX (green flash on success, red on error)
- `Tab` saves and moves to the next cell
- `Esc` cancels
- `Ctrl+Enter` saves in textarea mode
- NULL checkbox for nullable columns
- No page reload. Ever.

### Bulk Operations

- Checkbox column with select-all (supports indeterminate state when some rows are checked)
- Bulk action bar appears: "3 rows selected" with "Delete Selected" and "Clear"
- Delete sends a single `DELETE FROM table WHERE pk IN (?,?,?)` prepared statement
- Confirmation via themed modal dialog, not a native browser `confirm()`

### Insert Row

Click "Insert Row" in the toolbar. A form auto-generates from the table's column definitions:

| Column Type | Input |
|:------------|:------|
| `INT`, `BIGINT`, `DECIMAL` | `<input type="number">` |
| `DATE` | `<input type="date">` with native date picker |
| `DATETIME`, `TIMESTAMP` | `<input type="datetime-local">` |
| `TIME` | `<input type="time">` |
| `TEXT`, `MEDIUMTEXT`, `JSON`, `BLOB` | `<textarea>` |
| `ENUM('a','b','c')` | `<select>` with parsed values |
| `AUTO_INCREMENT` | Disabled with "AUTO" placeholder + "Manual" checkbox |
| Everything else | `<input type="text">` |

NULL checkbox per nullable column. Default values pre-filled. "Insert another" checkbox keeps the form open for batch entry — clears fields and focuses the first input after each insert. Shows the auto-generated ID on success.

### Empty Table State

Empty tables show a centered icon, "This table is empty" title, the table name, and an "Insert first row" button. Filtered views with no matches show the search term and a "Clear filter" button.

---

## 🔍 Search Across Tables

The feature phpMyAdmin doesn't have.

Open the **Search tab** (available when a database is selected), type any value, and Ledger scans every table in the database:

1. Gets all tables via `SHOW TABLE STATUS`
2. For each table, gets columns via `SHOW FULL COLUMNS`
3. Skips BLOB, BINARY, and GEOMETRY columns
4. Builds `SELECT * FROM table WHERE col1 LIKE ? OR col2 LIKE ? ... LIMIT 6` with prepared statements
5. Returns up to 5 matching rows per table (the 6th is used to detect "has more")
6. Identifies which specific columns matched by comparing `stripos` on returned values

**Results display:**
- Summary bar: "Found in 5 tables — 23+ matches across 39 tables searched"
- Per-table cards with table name, matched column badges (purple), row count, "Browse" link
- Full data rows with the search term highlighted in purple `<mark>` tags within matching cells
- Tables with 5+ matches show a "View all matches in tablename →" link that opens the Browse tab with the search filter pre-filled

Use cases: tracing foreign key values across tables, finding where an email address is stored, locating orphaned data, checking if a specific ID appears in junction tables.

---

## 🔗 ER Diagram

Interactive entity-relationship diagram rendered in pure SVG. No external charting libraries.

Open the **ER Diagram tab** and every table in the database is displayed as a card with a warm amber header, key icons (⚷ for PK, FK badge for foreign keys), column types, and nullable indicators (amber N). Row count shown in the header.

**Relationship lines** use crow's foot notation — three-pronged fork at the FK (many) end, double bars at the PK (one) end. Three line styles available via dropdown: Curved (bezier, default), Elbow (right-angle), and Straight.

**Layout saves automatically** — drag a table and the layout persists per database to `logs/er/`. Reopen the ER tab later and everything is exactly where you left it. Line style, zoom, and pan also saved.

**Right-click** any table for a context menu with Browse Data, Structure, SQL Editor, and Export SQL.

### Interaction

- **Drag** any table to reposition it. Lines redraw in real time. Layout auto-saves on drop.
- **Pan** by clicking empty space and dragging.
- **Zoom** with scroll wheel or `+`/`−` buttons.
- **Fit** button auto-centers all tables in the viewport.
- **Auto Layout** runs a force-directed physics simulation (repulsion, spring attraction, gravity, cooling).
- **Double-click** a table to navigate to its Structure tab.
- **Hover** a table to highlight its relationships — connected lines brighten, unrelated tables dim.

---

## 👁 Views, Triggers, Routines & Events

### Views

Views are listed alongside tables in the sidebar with a purple eye icon, and in a dedicated "Views" panel on the database overview page.

- **List** — each view shows its name, definer, and syntax-highlighted SQL definition
- **Create** — modal with name field and definition textarea (with live syntax highlighting). Validates and runs `CREATE VIEW`
- **Edit** — fetches current definition via `SHOW CREATE VIEW`, extracts the SELECT body, pre-fills the modal. Saves with `CREATE OR REPLACE VIEW`
- **Drop** — danger-styled confirm modal, `DROP VIEW IF EXISTS`
- **Browse** — click a view name to browse its data just like a table

### Triggers

Triggers are managed from the Structure tab in a dedicated panel at the bottom.

- **List** — each trigger shows timing badge (BEFORE = blue, AFTER = green), event badge (INSERT = green, UPDATE = amber, DELETE = red), name, definer, and syntax-highlighted body
- **Create** — modal with name, timing dropdown, event dropdown, and body textarea with live syntax highlighting
- **Edit** — drop-then-recreate with rollback on failure
- **Drop** — danger-styled confirm modal

### Routines (Stored Procedures & Functions)

Routines panel appears on the Structure tab for any selected database.

- **List** — shows type badge (PROC = blue, FUNC = purple), name, parameters with modes (IN/OUT/INOUT) and types, return type (functions), definer, and last modified date
- **View** — syntax-highlighted read-only viewer in a modal with a Copy button
- **Create** — modal with a full SQL editor and skeleton template (includes parameter examples, BEGIN/END block, DETERMINISTIC for functions)
- **Edit** — loads the current CREATE statement, drops the old routine and creates from the new SQL
- **Drop** — danger confirm modal

### Scheduled Events

Events panel sits below Routines on the Structure tab. MySQL's event scheduler runs SQL on a schedule — perfect for maintenance jobs, cleanups, and periodic aggregations.

- **List** — status badge (ENABLED = green, DISABLED = gray), name, type badge (RECURRING = blue, ONE TIME = purple), full schedule (`EVERY 1 DAY`, `AT 2026-05-01 00:00:00`, plus starts/ends for recurring), last executed timestamp, modified date
- **Scheduler status badge** — header shows the live value of `event_scheduler`. If events exist but the scheduler is `OFF`, a red warning banner appears with the exact command to enable it (easy to miss otherwise — events defined without the scheduler running never fire)
- **View** — syntax-highlighted viewer with Copy
- **Create / Edit** — SQL editor with a skeleton covering the common daily-recurring case with `ON COMPLETION PRESERVE`, `ENABLE`, `COMMENT`, and a `BEGIN ... END` block
- **Enable / Disable** — one-click toggle button per row (runs `ALTER EVENT ... ENABLE/DISABLE` without opening the definition)
- **Drop** — danger confirm modal

---

## 💾 Saved Queries

Save frequently-used queries with a name for quick access later. Click the **Save** button next to Execute in the SQL editor.

- Queries stored per-user in `logs/saved_queries.json`
- **Saved Queries panel** below Quick Queries — collapsible, shows count badge
- Each saved query shows name, database badge, relative timestamp
- **Click** to load into the editor
- **Rename** inline (click ✏️, type, press Enter)
- **Delete** with confirmation

---

## ⚡ Processes

Live view of `SHOW FULL PROCESSLIST` from a dedicated top-level tab. Turns Ledger from a schema editor into a tool you keep open to watch what's happening on your server.

- **Header stats** — Total connections, Active (non-Sleep), Sleeping, and the duration of the longest-running query. All refresh live.
- **Color-coded commands** — Sleep is muted, Query is green, Connect is blue, Killed is red, Binlog Dump is purple, everything else is gold. At a glance you see where attention is needed.
- **Color-scaled time** — query duration turns gold at 3s, amber at 10s, red at 60s. Sleeping connections stay dim regardless of how long they've been idle.
- **Auto-refresh** — Off (default), 2s, 5s, or 10s. Filter and scroll position are preserved across refreshes. A pulsing indicator shows when a fetch is in flight.
- **Filter** — live text filter across user, host, database, state, and the full query text. Plus a "Hide sleeping" checkbox that cuts through the noise on quiet servers where 90% of connections are idle.
- **Current session marker** — a green star next to the row representing your own Ledger connection (matched against `CONNECTION_ID()`). Self-kill is blocked in the UI *and* enforced server-side.
- **Kill button** — red danger action with a confirm modal that shows the query being terminated so you don't kill the wrong thing. Disabled in read-only mode.

Requires the `PROCESS` privilege to see other users' threads, and `SUPER` or `CONNECTION_ADMIN` to kill them. With a plain user, you'll see only your own sessions.

---

## 📜 Query History

Every query executed from the SQL tab is saved to the PHP session:

- **SQL text** — the full query, syntax-highlighted with the same tokenizer as the editor
- **Target database** — which database it ran against
- **Execution time** — in seconds with millisecond precision
- **Result** — rows returned (SELECT) or affected (INSERT/UPDATE/DELETE), including the zero-rows case
- **Error message** — on failure, the full database error is captured and displayed inline
- **Success/error** — green check or red × icon plus a subtle tinted row background (success green, error red) that automatically adopts each theme's colors
- **Timestamp** — displayed as relative time ("just now", "2m ago", "1h ago")

The **history panel** is a collapsible section below the SQL editor. Features:

- **Click to expand** — click any row to reveal its result summary or error message. A stronger tint and left-edge accent bar mark the expanded state. Click again to collapse.
- **Load button** — a pencil icon on hover pastes the SQL into the editor (kept separate from row click so you don't accidentally overwrite in-progress work while inspecting history)
- **Search/filter** — text input filters entries in real-time by SQL content
- **Delete individual** — × button on hover removes a single entry with a fade-out animation
- **Clear all** — trash button with confirmation modal wipes the session history
- **Duplicate suppression** — re-running the exact same query updates the existing entry's timestamp instead of creating a new one at the top

History is capped at `max_query_history` in config (default 50). Session-scoped — survives page navigations but clears on logout.

**Persistent drafts:** the SQL editor's current content auto-saves to `sessionStorage` keyed by database. Navigate away and back and your in-progress query is still there. Cleared after successful execution.

---

## 🏗 Table Management

### Create Table

On the database overview page, click **"Create Table"** to open a visual form:

- **Table options:** name, engine (InnoDB / MyISAM / MEMORY / ARCHIVE), collation, optional comment
- **Column rows:** dynamic add/remove. Each column has: name, type (searchable `<datalist>` with 35+ MySQL types), nullable checkbox, default value, PK, AI, Unique, Index checkboxes
- **Auto-wiring:** checking Auto Increment automatically checks Primary Key and unchecks Nullable. First column pre-filled as `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
- **Live SQL preview:** a collapsible `<details>` section shows the complete `CREATE TABLE` statement, syntax-highlighted, updating on every keystroke

On submit, the SQL is sent to the `execute_sql` AJAX endpoint and on success you're redirected to the new table's Structure tab.

### Create / Drop Database

- **Create:** form with name (validated to `[a-zA-Z0-9_-]`), character set (utf8mb4, utf8, latin1, ascii, binary), and collation. Available on the home page and via the sidebar "+" button on any page (opens a modal without leaving your current view).
- **Drop:** trash button on each database row. System databases (`mysql`, `information_schema`, `performance_schema`, `sys`) are protected — no trash button rendered.

### Table Operations

Every table row in the database overview has action buttons:

| Button | Action | Implementation |
|:-------|:-------|:---------------|
| 📝 Rename | Modal with name input | `RENAME TABLE old TO new` |
| 📋 Copy | Modal with name + "Structure + Data" / "Structure only" radio | `CREATE TABLE new LIKE old` + optional `INSERT INTO new SELECT * FROM old` |
| 🗑 Drop | Danger confirmation modal with row count | `DROP TABLE name` |

All operations: CSRF-protected, blocked in read-only mode, activity-logged.

### Structure Editor

On the Structure tab, every column is editable inline:

- Click the pencil icon → fields become editable: name, type (searchable datalist), nullable, default, extra, comment
- Save runs `ALTER TABLE ... CHANGE COLUMN`
- Add columns with position control: FIRST, AFTER specific column, or at end
- Drop columns with confirmation modal
- AUTO_INCREMENT: shows current next-ID with "Set" (any value) and "Reset" (MAX+1) buttons
- Collation column shows per-column collation (from `SHOW FULL COLUMNS`)
- CREATE statement at the bottom, syntax-highlighted using the tokenizer

### Foreign Key Visualization

The Structure tab queries `information_schema.KEY_COLUMN_USAGE` and `REFERENTIAL_CONSTRAINTS` to display:

- **PK columns**: gold left border + key icon badge
- **FK columns**: blue left border + FK icon badge with clickable link to the referenced table
- **Foreign Keys table**: constraint name, column, referenced table/column, ON DELETE and ON UPDATE rules with color-coded badges (CASCADE = red, RESTRICT = gray, SET NULL = amber)
- **Referenced By table**: which other tables reference this table's columns

### Maintenance

Dedicated panel on the Structure tab with four operations, each with a description and Run button:

- **Optimize** — rebuild the table to reclaim unused space and defragment
- **Analyze** — update key distribution statistics for the query optimizer
- **Check** — look for errors (read-only, safe in read-only mode)
- **Repair** — attempt to repair corrupted tables (MyISAM/ARIA only)

Buttons show "Running…" → "✓ Done" with status in the footer bar.

### Partitions

Full partition management on the Structure tab. For unpartitioned tables, a "Create Partitioning" button opens a modal with method selector (RANGE, LIST, HASH, KEY, RANGE COLUMNS, LIST COLUMNS), expression input, partition count, and definition textarea.

For partitioned tables, the panel shows method, expression, and a table of all partitions with name, description, row count, data size, and index size. Per-partition actions: Optimize, Rebuild, Truncate (with confirm), Drop (with danger confirm). Add new partitions or remove all partitioning.

---

## 📥 Import & Export

### Import SQL

Upload a `.sql` file and every statement is executed sequentially. The custom parser splits on `;` while respecting single-quoted and double-quoted strings, single-line comments (`--` and `#`), block comments (`/* */`), backtick-quoted identifiers, doubled-quote string escapes, and `DELIMITER` directives for stored procedures.

**Target mode selector** — two options:
- "Into existing database" — pick from a dropdown, statements execute in that context
- "New database from file" — no database selected, the file's own `CREATE DATABASE` and `USE` statements take effect

**Streaming with live progress.** The uploaded file is read from disk in 64 KB chunks rather than loaded into memory all at once, so imports of 300 MB+ dumps run in constant memory (~145 KB regardless of file size). The UI shows a four-phase progress bar:

1. **Upload** — real bytes-uploaded / total via `XMLHttpRequest.upload.onprogress`
2. **Counting** — quick pre-scan to give the bar a real denominator
3. **Importing** — `X / Y statements · N errors · MM:SS`, updated ~10 times per second
4. **Done** — inline result card, no page reload

**Fast mode** (toggle on the import form, default ON). Wraps DML statements in transactions and disables `foreign_key_checks` / `unique_checks` for the duration of the import, committing at each DDL boundary. Typical speedup is 5-20x on INSERT-heavy dumps because per-statement fsyncs become per-transaction fsyncs. On error, the current transaction rolls back and the import aborts; tables committed at earlier DDL boundaries remain. The result card explains this clearly. Turn the toggle off for per-statement isolation if you need it.

Results show per-statement success/error counts, total rows affected, and execution time. Failed statements are listed in a collapsible detail section with the SQL snippet and MySQL error. For very large imports, the error list is capped at the first 100 entries with "Showing first 100 of N failed statements" — total counts remain accurate.

### Import CSV

Upload a `.csv` file into any existing table:
- **Database dropdown** — dynamically loads tables via AJAX when changed
- **Delimiter** — comma, semicolon, tab, or pipe
- **Enclosure** — double or single quote
- **Header row** — toggle whether the first row contains column names

CSV is parsed with `fgetcsv()` (handles multiline quoted fields). Rows inserted via prepared statements. Empty strings and "NULL" values auto-convert to null. Column count mismatches are padded/trimmed. Errors collected per row (max 20).

### Export

- **Table export** — SQL dump (`CREATE TABLE` + multi-row `INSERT`) or CSV with column headers
- **Database export** — full dump with `CREATE DATABASE IF NOT EXISTS` + `USE` + all tables. Two output styles: single-statement (one logical pass per table) or phpMyAdmin-compatible (4-pass: structure, data, indexes, constraints)
- **Export page** — shows file size estimates and row counts per table. Works without a database selected (shows all databases with one-click download)

**Streaming exports.** All export routes stream rows directly to the response via an unbuffered PDO cursor, so memory stays roughly constant regardless of table size. Rows are batched into multi-row INSERT statements (`INSERT INTO t (cols) VALUES (...),(...),(...);`) — every 500 rows or ~4 MB, whichever first. This produces noticeably smaller dump files and makes re-imports substantially faster (~500x fewer statements to execute).

All file uploads support **drag-and-drop** with visual hover feedback.

> **Very large imports (multi-gigabyte):** even with fast mode and multi-row INSERTs, PHP-based imports are limited by round-trip latency between PHP and MySQL. For dumps over ~1 GB, using the `mysql` CLI directly (`mysql -u user -p < dump.sql`) is significantly faster than any PHP-based importer can achieve. Ledger is fine for typical operational dumps; the CLI is the right answer for full DB migrations.

---

## 🔒 Security & 2FA

Not bolted on. Ledger ships with a 12-step security chain — all opt-in so local dev stays frictionless, but production stays locked down.

| # | Layer | What It Does |
|:--|:------|:-------------|
| 1 | **Security headers** | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `X-XSS-Protection: 1`, HSTS, `Permissions-Policy` |
| 2 | **HTTPS enforcement** | Optional 301 redirect from HTTP to HTTPS |
| 3 | **IP whitelist** | Block by IP address or CIDR range (`192.168.1.0/24`). Checked before anything else loads |
| 4 | **Session hardening** | `httponly`, `secure`, `samesite=Lax` cookies. Periodic session ID regeneration. Configurable idle timeout |
| 5 | **Authentication** | Themed login page, multi-user support, bcrypt (`$2y$10$`) password hashing |
| 6 | **TOTP Two-Factor Auth** | Optional per-user TOTP (RFC 6238). Enable from Profile → scan QR code with any authenticator app (Google Authenticator, Authy, 1Password). 6-digit verification on login with ±1 time window for clock drift. Disable any time from Profile |
| 7 | **Brute force protection** | IP-based lockout after N failed attempts (configurable). Lockout duration configurable. Both password and 2FA failures count |
| 8 | **CSRF tokens** | Generated per session, validated on every POST form and every AJAX request. Meta tag in `<head>` for JS access |
| 9 | **Read-only mode** | Regex-based detection of write keywords (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `CREATE`, `GRANT`, `REVOKE`). Blocked at both UI and API level |
| 10 | **Hidden databases** | Configurable list filtered from sidebar, autocomplete, URL access, and export. Accessing a hidden DB via URL resets to home |
| 11 | **Query audit logging** | Every query logged to a file with timestamp, username, database, IP address, and execution time |
| 12 | **`.htaccess` rules** | Blocks direct web access to `config.php`, `includes/`, `logs/`, hidden files. Disables directory listing. Sets security headers at Apache level |

**First-run installer** means no default `admin/admin` ever exists. Passwords are bcrypt-hashed from the very first login.

```php
// Production config with 2FA enabled
'security' => [
    'require_auth'       => true,
    'users'              => [
        'admin' => [
            'password'    => '$2y$10$...bcrypt_hash...',
            'totp_secret' => 'BASE32ENCODEDSECRET',  // omit to disable 2FA
        ],
    ],
    'force_https'        => true,
    'ip_whitelist'       => ['10.0.0.0/8', '192.168.1.0/24'],
    'hidden_databases'   => ['information_schema', 'performance_schema', 'mysql', 'sys'],
    'read_only'          => false,
    'query_log'          => true,
    'max_login_attempts' => 5,
    'lockout_duration'   => 300,
],
```

---

## 🎨 Theming & Fonts

### 10 Built-In Themes

| Light | Dark |
|:------|:-----|
| **Atom One Light** — crisp white, vibrant blue/orange/green | **Carbon** — charcoal gray, VS Code blue |
| **Catppuccin Latte** — warm cream, soft pastel accents | **Catppuccin Mocha** — soothing pastels on warm dark |
| **Clean** — crisp white, blue accents *(default)* | **Dark Industrial** — deep black, neon green |
| **Forge** — white, green accents | **Dracula** — purple, pink, cyan on charcoal |
| **GitHub Light** — GitHub's familiar white/blue | **GitHub Dark** — GitHub's dark mode, charcoal/blue/green |
| **Gruvbox Light** — paper-warm, earthy tones | **Gruvbox Dark** — warm retro browns and oranges |
| **Lavender** — soft purple, violet accents | **Monokai** — warm yellows/greens on deep black |
| **Rosé Pine Dawn** — soft blush, muted purples | **Nord Dark** — arctic blue palette |
| **Sand** — warm beige, amber tones | **Solarized Dark** — Ethan Schoonover's classic |
| **Solarized Light** — the light companion | **Tokyo Night** — deep blue with neon cyan/purple |

20 themes total — each covers: surfaces, borders, text hierarchy, all SQL token colors (12 types), editor chrome, autocomplete, inline editing states, scrollbars, badges, modals, cell types, form inputs, buttons, panel sections, EXPLAIN badges, trigger badges, FK links, danger zones, and zebra striping.

Switch themes from the header dropdown — organized into Light and Dark groups, alphabetically sorted. Saved to cookie. Takes effect immediately, no page reload.

### Font Control

5 independently configurable font zones in Settings:

| Zone | CSS Variable | Applies To |
|:-----|:------------|:-----------|
| General UI | `--font-body` | Body text, labels, buttons, menus |
| Headings | `--font-heading` | Section titles, page headers |
| Sidebar | `--font-sidebar` | Database and table names |
| Table Data | `--font-data` | Cell values in data tables |
| SQL / Code | `--font-mono` | SQL editor, code blocks, query bar |

**28 curated fonts**: 16 sans-serif (DM Sans, Inter, Nunito Sans, Open Sans, Lato, Roboto, Source Sans 3, Outfit, Sora, Work Sans, Poppins, IBM Plex Sans + system fallbacks) and 12 monospace (JetBrains Mono, Fira Code, Source Code Pro, IBM Plex Mono, Roboto Mono, Inconsolata, Space Mono, Ubuntu Mono + system fallbacks).

Each zone has a live preview panel that updates as you select. Google Fonts are loaded on demand — only the fonts you actually choose are fetched.

### Custom Themes

```
themes/my-theme/
├── theme.json    # { "name": "My Theme", "author": "You", "type": "dark" }
└── style.css     # Override CSS variables
```

Drop a folder, refresh, it appears in the dropdown. The base theme (`dark-industrial/style.css`) defines every variable — your theme only needs to override the ones you want to change. See any existing theme for the reference.

### Zebra Striping

Alternating row backgrounds on all data tables, configurable per theme:

```css
--row-odd:  transparent;
--row-even: rgba(255, 255, 255, 0.015);  /* dark themes */
--row-even: rgba(0, 0, 0, 0.02);         /* light themes */
```

---

## ⌨️ Keyboard Shortcuts

Press `?` anywhere (except when typing in an input) to open the shortcut overlay.

| Key | Context | Action |
|:----|:--------|:-------|
| `?` | Global | Show/hide shortcut overlay |
| `Esc` | Global | Close overlay / cancel edit / dismiss modal |
| `Ctrl+Shift+S` | Global | Focus SQL editor (navigates to SQL tab if not there) |
| `Ctrl+Enter` | SQL editor | Execute query |
| `Tab` | SQL editor | Insert 4 spaces / accept autocomplete |
| `Shift+Tab` | SQL editor | Remove indent |
| `↑` `↓` | Autocomplete | Navigate suggestions |
| `Esc` | Autocomplete | Close dropdown |
| Click cell | Data table | Start inline edit |
| `Enter` | Inline edit | Save cell |
| `Tab` | Inline edit | Save and move to next cell |
| `Ctrl+Enter` | Textarea edit | Save large text cell |
| `Esc` | Inline edit | Cancel without saving |
| `Enter` | Modal | Confirm action |
| `Esc` | Modal | Cancel / dismiss |

---

## 📁 Project Structure

```
ledger/
├── index.php                    # Router + security chain
├── config.template.php          # Template for installer
├── install.php                  # 3-step first-run wizard
├── ajax.php                     # All AJAX endpoints (auth + CSRF protected)
├── .htaccess                    # Apache security rules
├── Dockerfile                   # PHP 8.2 + Apache image
├── docker-compose.yml           # Ledger + MySQL 8.0
├── DOCKER.md                    # Docker quick start guide
├── assets/
│   └── logo.svg
├── includes/
│   ├── Database.php             # PDO wrapper: browse, query, export, import,
│   │                            # FK queries, views, triggers, routines,
│   │                            # partitions, maintenance, table ops
│   ├── Auth.php                 # Auth + TOTP 2FA, CSRF, IP whitelist,
│   │                            # brute force, logging
│   ├── TOTP.php                 # Zero-dep RFC 6238 TOTP
│   ├── favorites.php            # Per-user table favorites (JSON)
│   ├── saved_queries.php        # Per-user saved queries (JSON)
│   ├── helpers.php              # Theme loader, font system, formatters
│   └── icons.php                # 30+ inline SVG icons
├── templates/
│   ├── layout.php               # HTML shell, header, sidebar, tabs, footer,
│   │                            # profile modal (password + 2FA)
│   ├── login.php                # Themed login page
│   ├── login_2fa.php            # TOTP verification page
│   ├── sidebar.php              # DB/table/view tree with filter, favorites
│   ├── browse.php               # Server/DB overview, data grid, FK drill-down,
│   │                            # row detail, inline edit, bulk select,
│   │                            # insert row, create table, table ops
│   ├── structure.php            # Editable columns, FK display, indexes,
│   │                            # CREATE statement, maintenance, partitions,
│   │                            # triggers CRUD, routines CRUD
│   ├── sql.php                  # SQL editor + EXPLAIN + saved queries +
│   │                            # query history
│   ├── search.php               # Search across all tables
│   ├── er.php                   # Interactive ER diagram with save/load,
│   │                            # crow's foot notation, right-click menu
│   ├── operations.php           # Alter, rename, move, copy, truncate, drop
│   ├── info.php                 # Table info
│   ├── export.php               # SQL/CSV export
│   ├── import.php               # SQL/CSV import with drag-drop + preview
│   ├── server_info.php          # Server stats
│   ├── settings.php             # Settings page + font customization
│   ├── settings_save.php        # Settings save helper (PRG)
│   └── connection_error.php     # Error display
├── js/
│   ├── ledger.js               # Tokenizer (612 tokens), autocomplete,
│   │                            # inline edit, bulk select, modals, CSRF,
│   │                            # favorites, sidebar filter, persistent drafts,
│   │                            # mini-editor highlighter, shortcut overlay
│   └── qr.js                    # QR code generator (MIT, Kazuhiko Arase)
├── themes/                      # 20 themes (10 light + 10 dark)
└── logs/                        # Query logs, favorites, saved queries,
    └── er/                      # ER diagram layouts (per database)
```

**One external library:** [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase (MIT license, 21KB minified) for TOTP 2FA QR codes. Everything else is written from scratch. No jQuery, no React, no Vue, no Bootstrap, no Tailwind, no CodeMirror, no Monaco, no D3, no Composer, no npm. The entire tool runs offline after the initial Google Fonts load (and even that is optional — it falls back to system fonts).

---

## 📋 Requirements

| | Minimum | Recommended |
|:--|:--------|:------------|
| **PHP** | 7.4 | 8.2+ |
| **MySQL** | 5.7 | 8.0+ |
| **MariaDB** | 10.3 | 10.11+ |
| **Web Server** | Apache 2.4 with `mod_rewrite` | Apache 2.4 |

PDO MySQL extension required (`php-pdo` + `php-mysql`). Sessions must be enabled. `upload_max_filesize` affects import limits.

---

## 🔐 Privacy

Ledger does not phone home from the server. The installed application makes no outbound network calls from PHP during normal operation.

There is **one optional** client-side update check: when **Check for updates** is enabled in Settings (default on), the admin's browser fetches `https://tryledger.dev/api/version.json` at most once per day. The request sends no parameters, no cookies, no identifying data — only the standard things any web server logs for any request (IP, user agent, timestamp). Disable it under **Settings → Check for updates** if you want zero outbound traffic.

Full details in [SECURITY.md](SECURITY.md#privacy--network-behavior).

---

## 🤝 Contributing

1. Fork → branch → commit → PR
2. **Theme contributions** especially welcome — add a `themes/your-theme/` folder
3. Bug reports and feature requests via [Issues](https://github.com/ClearanceClarence/Ledger/issues)

---

## 📄 License

[MIT](LICENSE) — use it anywhere, modify it freely, include it in commercial projects.

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

<p align="center">
  <img src="https://raw.githubusercontent.com/ClearanceClarence/Ledger/refs/heads/main/assets/logo.svg" alt="Ledger" width="380">
  <br>
  <sub>Built with PHP, vanilla JS, and zero external dependencies.</sub>
  <br>
  <sub>By developers, for developers.</sub>
</p>