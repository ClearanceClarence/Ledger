<p align="center">
  <img src="https://raw.githubusercontent.com/ClearanceClarence/Ledger/refs/heads/main/ledger/assets/logo.svg" alt="Ledger" width="40">
</p>

# Changelog

All notable changes to [Ledger](https://github.com/ClearanceClarence/Ledger) are documented here.

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
