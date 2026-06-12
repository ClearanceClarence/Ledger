<p align="center">
  <img src="https://raw.githubusercontent.com/ClearanceClarence/Ledger/refs/heads/main/assets/logo.svg" alt="Ledger" width="40">
</p>

# Changelog

All notable changes to [Ledger](https://github.com/ClearanceClarence/Ledger) are documented here.

---

## [1.0.2-beta] — 2026-06-12

> **Security + polish release.** A full self-audit of the codebase turned up a handful of real issues — the most serious being that `X-Forwarded-For` was trusted as the client IP (bypassing the IP allowlist and brute-force lockout), that the read-only mode could be slipped past via the EXPLAIN endpoint and comment-prefixed writes, and that the Saved Queries feature was outright broken by a dropped function signature. All fixed here. On top of that: the keyboard-shortcut overlay was rebuilt with search, the update check moved server-side, the update notification became a toast, a proper favicon set shipped, and the `force_https` setting can no longer lock you out.

### Security

- **`X-Forwarded-For` is no longer trusted by default.** It was consulted before `REMOTE_ADDR`, so on a direct deployment any client could send the header to spoof their IP — bypassing the IP allowlist, defeating brute-force lockout (rotate the header → unlimited attempts), and poisoning the activity log. Forwarded headers are now honored only when the request originates from an IP in the new `trusted_proxies` config list, and the left-most list entry is validated with `filter_var(...,FILTER_VALIDATE_IP)`. New setting under Settings → Trusted Proxies; empty by default, which means forwarded headers are ignored entirely.
- **Read-only mode hardened.** Three gaps closed: stacked queries are now blocked at the driver (`MYSQL_ATTR_MULTI_STATEMENTS=false`), so `EXPLAIN SELECT 1; DROP TABLE x` can't smuggle a write through the explain endpoint; the `explain_query` AJAX action gained the read-only guard every other write path already had; and `isWriteQuery()` now strips leading SQL comments before keyword-matching and recognizes writing CTEs (`WITH … DELETE …`), both of which previously slipped past as non-writes.
- **Stored XSS in the Processes view fixed.** Every field in the process-list row was HTML-escaped except the database name, which can legally contain markup (`CREATE DATABASE` accepts almost anything in backticks). A DB named with an `onerror` payload would execute when an admin opened the Processes tab. Now escaped like the rest.
- **DSN parameter injection guarded.** The `db` request parameter is rejected if it contains `;` or control characters before being interpolated into the PDO DSN, closing a path to inject extra connection parameters.
- **TOTP codes can no longer be replayed.** A valid 2FA code was accepted for its full ±1 step (~90s) window with no record of use. The matched time-step is now burned per-user, so a captured code can't be reused within its validity window.
- **Lockout state moved out of the system temp directory.** Failed-attempt tracking lived in `sys_get_temp_dir()`, which is world-readable on shared hosts — another tenant could read the attempt/IP data or clear the counters. It now lives in the web-protected `logs/` directory.
- **Legacy plaintext credentials auto-upgrade to bcrypt.** A hand-edited plaintext password in `config.php` still logs in once, then is silently rewritten as a bcrypt hash so it never persists as plaintext.

### Fixed

- **Saved Queries no longer fatals.** A dropped `function ledger_saved_queries_file(): string` signature left the function undefined while the rest of the file still parsed, so every saved-query action (`save`, `list`, `update`, `delete`) returned a 500 / malformed JSON. The whole feature was dead. Restored.
- **`force_https` can no longer lock you out.** Enabling "Force HTTPS redirect" on a server with no working HTTPS listener (e.g. default XAMPP, no 443) previously 301'd every request to an unreachable `https://` URL — and because the redirect was a permanent 301, browsers cached it indefinitely, so even fixing `config.php` didn't stop the auto-redirect until the cache was cleared. Now: the redirect is a 302; the setting can only be enabled from an already-HTTPS session (proving HTTPS works before committing to it); and the Settings checkbox is disabled over plain HTTP with an inline explanation.
- **Latent `ReferenceError` in the old update-banner code.** A fallback path referenced an undefined `repo` variable. Never triggered in practice (the upstream always supplied a URL) but would have crashed if it hadn't.

### New Features

- **Keyboard-shortcut overlay rebuilt.** The `?` overlay now has live search (matches key labels, descriptions, and section names — `sql` pulls in the whole SQL Editor section), a single-column layout that gives descriptions the full row width, platform-aware key labels (`⌘ ⇧ ⌥ ⏎` on Mac, detected via `navigator.platform`), command-palette Esc behavior (first Esc clears search, second closes), an empty state, and a mobile-stacked layout under 600px.
- **Favicon set.** Previously no favicon link existed in any head section, so browsers showed the default globe. Ships the three-bar ledger mark on a rounded-square green background, with light (`#16a34a`) and dark (`#4ade80`) variants selected via `prefers-color-scheme`, plus a 32px PNG fallback and a 180px apple-touch-icon. Wired into the main UI, installer, login, and 2FA head sections.

### Changed

- **Update check runs through a server-side proxy.** The browser previously fetched `tryledger.dev/api/version.json` directly via CORS, which failed silently whenever a CDN stripped the CORS headers and exposed every admin's IP to the endpoint. The browser now calls a same-origin `ajax.php?action=check_update`, handled by a new `UpdateCheck` class that caches the upstream response in `logs/version-cache.json` for 24h, fetches server-side via cURL (with a `file_get_contents` fallback), serves stale cache marked `stale:true` if the upstream is down, and sends no cookies or referer. One outbound request per install per day instead of one per browser per day. The check runs before the DB connection, so the banner still works when MySQL is down.
- **Update notification is now a toast.** The full-width banner that pushed page content down was replaced with a small top-right toast (slide-in, pulse dot, theme-driven colors across all 20 themes). Security updates get a distinct amber treatment and omit the dismiss button. Respects `prefers-reduced-motion`.

### Deployment

- **nginx config shipped.** The repo only carried an `.htaccess`, so on nginx none of the file protections applied and `GET /logs/queries.log` would serve the query history as plaintext. Added `nginx.conf.example` mirroring the Apache rules, plus a deny-all `logs/.htaccess` as belt-and-suspenders.
- **`.gitattributes` added** pinning all text files to LF, so Windows checkouts stop producing phantom LF↔CRLF churn in diffs.

---

## [1.0.1-beta] — 2026-05-19

> **Hardening release.** First public bug-report cycle uncovered three real problems: the installer was too forgiving of bad input, post-login redirects didn't preserve the user's destination, and exports/imports broke on databases larger than the default `memory_limit`. This release fixes all three. Also brings a quality-of-life redesign of the header info chips and an honest "fast mode" toggle for the SQL import that wraps it in transactions for a 5-20x speedup on large dumps.

### New Features

- **Live progress UI for SQL imports.** Replaces the previous "form submit, wait two minutes, hope for the best" experience. The new flow streams real progress through four phases: file upload (real bytes-uploaded/total via `XMLHttpRequest.upload.onprogress`), pre-scan to count statements (gives the bar a real denominator), execution (rate-limited NDJSON progress events ~10/sec showing `X / Y statements · N errors · MM:SS`), and inline result rendering with no page reload. Falls back to the original synchronous form submit if JavaScript or `FormData` isn't available.
- **Fast import mode** (toggle on the SQL import form, default ON). Disables foreign-key and unique checks for the duration of the import, wraps DML statements in transactions, and commits at each DDL boundary. Typical speedup is 5-20x on INSERT-heavy dumps because per-statement fsyncs become per-transaction fsyncs. On error, the current transaction rolls back and the import aborts — earlier transactions (at previous CREATE/ALTER/DROP boundaries) remain. The result card reports this honestly so users know which tables are in place after an abort. Advanced users can turn the toggle off for per-statement isolation.
- **Multi-row INSERT exports.** `INSERT INTO t (cols) VALUES (...),(...),(...);` form, batched every 500 rows or ~4 MB (whichever first, staying safely under `max_allowed_packet`). Cuts exported statement count and file size by ~500x compared to the previous one-INSERT-per-row format. Resulting dumps import dramatically faster, especially with fast mode on.
- **Password generator and strength meter in the installer.** Generate button uses `crypto.getRandomValues()` to produce a 20-character password from a 78-char alphabet (avoids ambiguous chars like `0/O`, `1/l/I`, and shell-unfriendly chars like `\` `'` `` ` `` `$`). Fills both password fields, auto-reveals them, copies to clipboard. The live strength meter shows a 5-segment bar (red→green) and a 4-rule visible checklist (length, max length, not common, ≠ username). Submit button stays disabled until the password is strong enough and matches the confirmation.
- **Reveal-eye toggle** on both password fields in the installer.

### Fixed

- **Post-login redirect now preserves the destination URL.** Visiting `/?db=astrahedron&tab=sql` while logged out previously redirected to the login screen correctly, but successful login landed the user on `/` instead of their intended URL. The cause was an interaction with the session cookie's `SameSite=Strict` flag that sometimes prevented the cookie from being delivered on the capturing GET request. Fix: pass the return URL through both the session AND a hidden form field, prefer POST over session. Open-redirect protection is unchanged (rejects full URLs, protocol-relative URLs, absolute paths, scheme markers, control characters, auth-flow actions, and anything over 2048 chars).
- **Database connection errors during install are now actionable.** Instead of `Connection failed: SQLSTATE[HY000] [1045] Access denied for user 'rooting'@'localhost' (using password: NO)` the installer translates common SQLSTATE codes into plain English with specific hints — wrong password vs empty password, DNS failure vs connection refused vs timeout, SSL handshake issues, bind-address restrictions, and so on.
- **Installer step navigation is now guarded.** Direct GET access to `?step=2` or `?step=3` (via the back button, manual URL editing, or a stale bookmark) reliably falls back to step 1 instead of rendering a blank form. Only POST submissions can advance through the installer.
- **Password rules in the installer are stricter and visible.** Minimum 8 characters (was 6), maximum 72 characters (bcrypt's truncation limit — was unlimited so users could think they had a 256-char password when bcrypt only saw the first 72), banned against a ~100-entry list of most-leaked passwords, username can't equal password. All enforced server-side; the client-side meter is advisory only.
- **SQL exports stream instead of buffering.** All four export routes (`export_sql`, `export_csv`, single-statement `export_db`, phpMyAdmin-compatible `export_db`) now stream rows directly to output via an unbuffered PDO cursor. Memory stays constant regardless of table size. Previously a ~300 MB database would exhaust `memory_limit` before any output was sent.
- **SQL imports stream from disk.** The uploaded file is read in 64 KB chunks via `fopen`/`fread` instead of `file_get_contents()`, and the statement parser is now resumable across chunk boundaries (handles partial strings, comments, and `DELIMITER` directives at chunk edges). The result array is capped at 50 success + 100 error entries with a `truncated` flag; total counts remain accurate. For a 12 MB / 100K-statement test dump, peak memory dropped from ~30 MB to ~145 KB.

### Changed

- **Header info chips redesigned.** Pill shape replaces rounded rectangles. Each chip type carries a `--chip-accent` CSS variable that colors only its icon — server (accent green), database version (info blue), database count (purple), uptime (warning amber), PHP version (neutral). The server chip gets a soft pulsing dot to telegraph "connection healthy." Uses theme variables throughout, so all 20 themes pick up the styling automatically via the base+overlay theme architecture. Respects `prefers-reduced-motion`.

### Internal

- **`Database::splitSqlStatementsStreaming()`** — refactored variant of the existing tokenizer that returns `[completeStatements, remainder, currentDelimiter]`. When the parser encounters an unterminated statement, quoted string, comment, or `DELIMITER` directive at the end of its input, it stops and returns the unconsumed portion as the remainder, to be prepended to the next chunk. The non-streaming `splitSqlStatements()` is now a wrapper that flushes the remainder as a final statement.
- **`Database::executeSqlDumpFromFile()`** — streams the file from disk, executes each statement as it's parsed via the streaming tokenizer. Takes an optional progress callback (invoked every N statements + on every error) and an optional `fast` flag (transactional batching with DDL-boundary commits).
- **`Database::countStatementsInFile()`** — fast pre-scan that runs the streaming parser in count-only mode (128 KB chunks, no statement bodies stored). Used to give the progress bar a real denominator before execution starts.
- **`Database::streamTableData()`** — unbuffered cursor + multi-row INSERT batching. Replaces the old fetchAll-based `exportTable()` for HTTP export paths. The original `exportTable()` returning a string is kept for any non-HTTP caller.
- **`Database::streamTableCsv()`** — companion CSV variant of `streamTableData()`. Streams to `php://output` row-by-row.
- **`ajax.php` action `import_stream`** — new endpoint that streams NDJSON progress events. Drains output buffers, sends `X-Accel-Buffering: no` for nginx, calls `set_time_limit(0)`. Rate-limits emissions to ~10/sec so a fast dump doesn't saturate the network.

### Known Issues

- **Very large imports are still slow even with fast mode on.** A 1.6M-statement dump projects ~10-15 minutes of run-time on a typical server even after the transactional batching wins. The fundamental limit is round-trip latency between PHP and MySQL — every statement is a separate query. Users importing multi-gigabyte dumps should use the `mysql` CLI directly (`mysql < dump.sql`) which is significantly faster than anything we can do from PHP.
- **`upload_max_filesize` and `post_max_size`** in `php.ini` still cap what PHP will accept regardless of any code-level improvements. The default is often 8-100 MB on shared hosts. Raise these in `php.ini` to allow larger uploads, or use the CLI for very large imports.

---

## [1.0.0-beta] — 2026-05-11

> **First public release.** Ledger is now available for general use. The feature set is complete and tested in active development since December 2025, but this is the first version released outside of internal use. Expect rough edges on PHP versions, MariaDB forks, and hosting environments other than the development setup. Please file issues for anything that breaks.
>
> This release also brings multi-statement SQL execution. Paste a migration, hit Run, get per-statement results. USE, CREATE DATABASE, and DROP DATABASE all work in the editor.

### New Features

- **Client-side update check.** When logged in, Ledger's admin UI now checks `https://tryledger.dev/api/version.json` at most once per day to see if a newer release is available, and shows a dismissible banner if one is. The request runs in the user's browser — no server-side outbound call is made, and the endpoint sends no parameters or cookies. Security updates get a distinct non-dismissible banner. The check can be turned off in **Settings → Check for updates**. See [SECURITY.md](SECURITY.md#privacy--network-behavior) for the full privacy disclosure.
- **Multi-statement execution in the SQL editor.** Paste a full migration with multiple `CREATE TABLE`, `INSERT`, `ALTER` statements separated by semicolons and run them all in one go. Each statement is executed sequentially and its result is shown in its own card with row counts, errors, and timing. Execution stops on the first failure (use `START TRANSACTION` / `COMMIT` if you need atomicity).
- **DELIMITER directive support.** The splitter handles `DELIMITER //` blocks correctly, so you can paste stored procedure definitions with `BEGIN ... END//` bodies without breaking the parse.
- **USE works in the SQL editor.** Paste `USE mydb; CREATE TABLE foo (...); INSERT INTO foo ...` and it executes correctly. Previously the second and third statements ran against the wrong database because each statement reconnected and lost the USE context.
- **Bootstrapping scripts work.** Paste `CREATE DATABASE new; USE new; CREATE TABLE t (...);` and it runs end-to-end. Previously the initial connection to `new` failed because the database didn't exist yet.

### Fixed

- **Multi-statement read-only enforcement.** `isWriteQuery()` previously only checked the first keyword in the input, so a read-only user pasting `USE foo; DROP TABLE bar;` would have the DROP slip through. Now every statement in the batch is checked.

### Internal

- New `Database::executeQueries()` method routes single statements through the existing fast path (backward-compatible) and multi-statement input through a per-statement loop with aggregated results. Connects once and reuses one PDO across all statements in the batch, preserving session state (active database, temp tables, user variables).
- New `Database::splitSqlStatements()` public method handles all the cases the previous private splitter missed: backtick-quoted identifiers, doubled-quote string escapes (`'it''s'`), hash comments (`#`), DELIMITER directives, and pure-comment lines. The old private splitter is removed; the existing `executeSqlDump` importer transparently uses the new one.
- When input contains `CREATE DATABASE`, `DROP DATABASE`, or `USE`, the batch connects without a target database to avoid "unknown database" errors at connect time. If the original URL had a database set, a `USE` is issued after connect to restore intended context.
- `USE`, `CREATE/DROP DATABASE`, `CREATE/DROP SCHEMA`, and `SET` now route through `PDO::exec()` instead of `prepare() + execute()`. PHP-PDO mishandles prepared `USE` on some MySQL versions and these statements don't benefit from prepared-statement caching anyway.
- Added `WITH` (CTE) to the SELECT detector so CTEs are recognized as resultset queries.

### Project Infrastructure

- **`CODE_OF_CONDUCT.md`** — Contributor Covenant 2.1, rewritten in plain language. Establishes baseline expectations and enforcement process.
- **Issue templates** under `.github/ISSUE_TEMPLATE/` for bug reports and feature requests. Includes a config that disables blank issues and points security reports at the private advisory channel.
- **Pull request template** under `.github/pull_request_template.md` with a checklist that catches the common contribution mistakes (no dependencies added, CHANGELOG updated, scope kept narrow).
- **`.github/FUNDING.yml`** scaffold for optional sponsor links (commented out — uncomment to activate).


---

*Development history prior to the public 1.0.0-beta release is preserved in the git commit log and tag list. The project was originally developed under the name DBForge between December 2025 and May 2026 before being rebranded to Ledger for public release.*
