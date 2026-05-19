# WIP Changelog — v1.0.1-beta-wip

> **Status:** Work in progress. Not a published release.
>
> This file collects changes made on top of [v1.0.0-beta](CHANGELOG.md) that haven't been cut into a tagged release yet. When v1.0.1-beta (or v1.0.0 stable) is ready to ship, these notes get moved into `CHANGELOG.md` and this file is reset.

Notes are dated when added so it's clear what's been changing.

---

## Login redirect preserves the user's intended destination

Previously, visiting a URL like `/?db=astrahedron&tab=sql` while logged out would correctly redirect to the login screen — but after a successful login, the user always landed back on the dashboard (`/`), losing their original destination.

Now the original query string is saved to the session on the unauthenticated GET request, and successful login (or successful 2FA verification) redirects there instead of the default dashboard.

Open-redirect prevention: the saved target is re-validated before use. Rejected:

- Fully-qualified URLs (`https://evil.com/...`)
- Protocol-relative URLs (`//evil.com/...`)
- Absolute paths (`/admin/...`)
- Any scheme marker (`javascript://...`)
- Raw or URL-encoded control characters (defends against CRLF/header injection)
- Auth-flow actions (`action=login`, `action=logout`, `action=verify_2fa`)
- Anything over 2048 chars

Only relative query strings within this Ledger install can be honored. The saved target is consumed (cleared from session) on first successful use.

---

## Fast import mode + multi-row INSERT exports

The streaming progress UI from the prior commit made it visible that imports were slow: a 1.65M-statement dump was reporting ~100 statements/sec, projecting hours of total time. Two changes make this practical.

### Multi-row INSERT exports

`streamTableData()` now batches rows into multi-row `INSERT` statements (`INSERT INTO t (cols) VALUES (...),(...),(...);`) instead of one INSERT per row.

- Batch boundary: every 500 rows OR ~4 MB of accumulated VALUES (whichever comes first), to stay well under MySQL's `max_allowed_packet` (default 64 MB, often 4-16 MB on shared hosts)
- Memory profile unchanged: cursor is still unbuffered, accumulator never exceeds the batch threshold
- Resulting dump files are noticeably smaller (one statement prefix per ~500 rows instead of per row) AND import faster when re-imported (~500x fewer statements means ~500x fewer round trips)

### Fast import mode

New option on the SQL import form, default **ON**: wraps DML statements in transactions and disables non-essential checks.

What it does:

- `SET unique_checks=0` and `SET foreign_key_checks=0` at the start of the import, restored at the end
- Wraps batches of DML (INSERT/UPDATE/DELETE/REPLACE) in transactions, committing at each DDL boundary (CREATE/ALTER/DROP/TRUNCATE etc. in MySQL implicitly commits any open transaction anyway, so we commit cleanly *before* the DDL rather than getting surprise-committed mid-stream)
- On any SQL error: rolls back the current open transaction (the failing table's incomplete data) and aborts the import

Speedup comes mostly from fewer fsyncs. Without fast mode, InnoDB fsyncs after every committed statement (one row at a time = thousands of fsyncs/sec needed). With fast mode, fsyncs are batched per-table. Typical speedup is 5-20x on INSERT-heavy dumps; the exact ratio depends on disk speed, schema indexing, and how many distinct tables are in the dump.

Failure model — honest disclosure:

Fast mode is **all-or-nothing within each table**, not across the entire import. Tables fully committed before an error remain. The UI reports this clearly in the result card (`"Import aborted. Fast mode was on, so the failing table's transaction was rolled back. Tables committed before the failure remain in place. Re-running the import will re-import everything. To get per-statement isolation instead, turn off Fast mode."`).

True all-or-nothing across the whole import isn't possible without disabling DDL inside the dump file, which would break most real-world dumps. The per-table commit boundary is a deliberate compromise: get the speed of transactions where it matters (the data-heavy parts), accept that DDL is its own commit boundary.

Advanced users importing into critical systems can turn Fast mode off for per-statement isolation and full error reporting at the cost of speed.

---

## Streaming SQL imports with live progress

The import flow used to be a black box: form submit, browser spinner, no feedback for minutes, then a page reload with results. For a 279 MB dump that means 2+ minutes of staring at a spinner.

Now the SQL import streams real progress through four phases:

1. **Upload** — XHR `upload.onprogress` tracks bytes uploaded vs total. Real-time bar.
2. **Counting** — server runs `countStatementsInFile()` (fast scan via the streaming parser, count-only mode) to give the bar a denominator. Indeterminate animation during this brief pre-scan.
3. **Executing** — server streams NDJSON to the client (one JSON object per line) via `ajax.php?action=import_stream`. Each line carries `{total, success, errors, rows, estimate, elapsed}`. JS reads the response incrementally via `xhr.responseText` slicing and updates the bar every event.
4. **Done** — final NDJSON message carries the full aggregate result (same shape as before). UI renders the result card inline without a page reload.

Key constraints handled:

- Output buffering: `ob_end_flush()` + `flush()` after every progress line so messages reach the browser immediately. `X-Accel-Buffering: no` header tells nginx not to buffer if it's the reverse proxy.
- Rate limiting: progress emissions are throttled to ~10/sec server-side, so a fast dump doesn't saturate the network with thousands of NDJSON lines for a 5-second import.
- Fallback: if JS is disabled or `FormData`/`XMLHttpRequest` are missing, the form submits normally and the existing PHP-rendered result page works. No regression.
- CSRF: the existing `csrfField()` on the form carries through via `FormData(form)`.

Memory profile unchanged from the previous streaming work — still ~145 KB for a 12 MB dump.

---

## Streaming exports for large databases

Previously, exporting a database via `?action=export_db` loaded every row of every table into PHP memory before sending a byte to the browser. For a 279 MB database (~1M rows across all tables, exported as INSERT statements) this could:

- Exhaust PHP's `memory_limit` (commonly 128–256 MB) and abort the request
- Exceed `max_execution_time` (commonly 30s) before the response started
- Cause the browser to time out waiting for the first byte

The export path now streams row-by-row:

- New methods `Database::streamTableData()` and `Database::streamTableCsv()` use an unbuffered PDO cursor (`MYSQL_ATTR_USE_BUFFERED_QUERY = false`) so rows arrive one at a time and memory stays constant regardless of table size
- Each `INSERT` statement is `echo`d directly instead of accumulating in a `$output` string
- Output is flushed every 200 INSERTs (or 500 CSV rows) so the browser sees download progress incrementally
- `set_time_limit(0)` is called at the start of any export to bypass PHP's script timeout
- All active output buffers are drained at the start of an export so internal PHP buffering doesn't hold the whole response in RAM

Applies to: `?action=export_sql` (single table), `?action=export_csv` (single table CSV), `?action=export_db` (whole DB, both single-statement and phpMyAdmin-compatible styles).

The original `exportTable()` and `exportTableCsv()` methods that return strings are kept for any other caller (testing, AJAX previews) but no longer used by the HTTP export routes.

---

## Installer hardening

### Step navigation guard

Direct URL access to `?step=2` or `?step=3` (via the back button, manual URL editing, or a stale bookmark) previously rendered the corresponding form with empty fields, which was confusing and could let users submit step 3 before completing step 2 in the same session.

Now: GET requests with a step other than 1 fall back to step 1 unconditionally. Only POST submissions can advance through the installer.

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $step !== 1) {
    $step = 1;
}
```

### Friendly database connection errors

Previously, a failed DB connection during install showed the raw PDO exception verbatim:

> `Connection failed: SQLSTATE[HY000] [1045] Access denied for user 'rooting'@'localhost' (using password: NO)`

Now the installer translates common SQLSTATE codes and message patterns into actionable plain English. Each variant points at the specific likely cause:

- **1045 / "Access denied"** → "MySQL refused the connection for user X. The username or password is wrong" (with a hint about empty-password if the password field was blank)
- **2002 / DNS failure / unreachable host** → "Couldn't reach the MySQL server at X:Y. Is the server running, and is the host/port correct? Try 127.0.0.1 instead of localhost on Linux if you're not sure."
- **2003 / Connection timeout** → "Connection to X:Y timed out after 5 seconds. The server may be down, behind a firewall, or on a different port."
- **Connection refused** → "MySQL on X:Y refused the connection. Check that MySQL is running and accepting connections on that port."
- **1130 / "is not allowed to connect"** → "MySQL is not configured to accept connections from this host. Check your MySQL bind-address setting and the user's allowed hosts."
- **SSL/TLS failures** → "TLS/SSL handshake with MySQL failed. If your server requires SSL connections, the PHP pdo_mysql client needs to be configured to provide a certificate."

Fallback for anything unknown still surfaces the raw MySQL message, but framed as "MySQL refused the connection. The server reported: …" rather than "Connection failed."

### Password validation rules

The previous admin-account password policy was 6 characters minimum, no maximum, with a 7-entry hardcoded blocklist. That was too lax.

The new policy:

- **8 characters minimum** (was 6)
- **72 characters maximum** (was unlimited; bcrypt silently truncates anything above 72, so allowing longer values gave users a false sense of security)
- **Blocked against ~100 most-leaked passwords** including the top 30 globally, common variations like `password123` and `p@ssw0rd`, service/app names like `ledger`/`mysql`/`admin`/`changeme`, keyboard walks, year strings (`20242024`, `20252025`), and common "welcome" / "test" / "demo" patterns. Sourced from public breach analyses
- **Username can't equal password** (case-insensitive)
- **Username constraints:** 3–64 chars, `[a-zA-Z0-9_.-]` only (was: just `>= 3` with no character restrictions)
- **Password confirmation must match** (already existed; kept)

All checks are enforced server-side. Client-side checks below are advisory only.

### Live password strength UI

The password input on step 2 of the installer now shows:

- A **5-segment strength bar** that shifts color from red → amber → green as the password gets stronger, with a label ("Very weak" → "Weak" → "Fair" → "Good" → "Strong")
- A **4-rule checklist** below the input that ticks off in real time:
  - At least 8 characters
  - No more than 72 characters
  - Not a commonly-leaked password
  - Different from the username
- A **"Passwords match"** / "Passwords do not match" indicator under the confirmation field
- The **submit button is disabled** until rules pass, passwords match, and strength is at least Fair (score ≥ 3)

The scoring algorithm is zxcvbn-inspired but written from scratch in ~60 lines of vanilla JS — no library dependency. Bonus points for length (every 4 characters past 8), bounded contribution from character-class diversity, penalties for predictable patterns (all-same-char, sequential digits, keyboard rows, single-class strings).

### Password generator

A **Generate** button next to the password label produces a strong random password using `crypto.getRandomValues()` (the cryptographically secure RNG, not `Math.random()`).

- **20 characters** drawn from a 78-character alphabet (lowercase + uppercase + digits + safe symbols)
- **Avoids ambiguous characters**: no `0` / `O`, `1` / `l` / `I`
- **Avoids shell-unfriendly characters**: no `\`, `"`, `'`, `` ` ``, `$`
- **Fills both password and confirm-password** fields in one click
- **Auto-reveals both fields** so the user can verify what was generated
- **Copies to clipboard** via `navigator.clipboard.writeText()` so it can be pasted into a password manager before submit
- Shows **"✓ Copied"** feedback for 2 seconds, falls back to "Generated" if the clipboard API is unavailable (e.g. non-HTTPS context)

### Reveal-eye toggles

Both password fields on step 2 now have a **show/hide eye button** inside the input on the right edge. Click to flip between `type="password"` and `type="text"`. ARIA label and icon update with the state. Standard pattern.

### Browser-side input constraints

The HTML `minlength` on the password input has been bumped from 6 to 8 to match the server-side rule, and `maxlength="72"` has been added to both password inputs to enforce the bcrypt limit at the browser level. The username input gets `maxlength="64"` and `pattern="[a-zA-Z0-9_.\-]+"` to match the server's constraint.

These are advisory — the server still validates independently — but they prevent the obvious cases of users typing past the limit and being confused about why submission fails.

---

## Notes

- All installer changes are confined to `install.php`. No other files were touched in this round.
- Server-side validation is the source of truth. The client-side strength meter, reveal-eye, and generator are UX improvements only; bypassing them via DevTools still results in a rejected submission if the password violates the rules.
- The common-password blocklist on the server (~100 entries) is larger than the client-side preview list (~35 entries). The client list exists only to give immediate feedback on the worst choices while the user is typing; the full check happens on submit.

## Still pending before v1.0.1-beta is ready to tag

- [ ] End-to-end test on a fresh install with a real MySQL backend
- [ ] Test with PHP 7.4 (the stated minimum) in addition to PHP 8.x
- [ ] Test with MariaDB 10.3 (the stated minimum)
- [ ] Test installer flow on Windows / XAMPP / Laragon environments
- [ ] Verify the version-check banner appears correctly on installs running this build (since this build will now report `1.0.1-beta-wip` and `tryledger.dev/api/version.json` says `1.0.0-beta` is current — the comparison should say no update is available, which is correct behavior for a pre-release build)

## Known issues with this WIP build

- **Semver edge case with the `-wip` suffix.** The current version-check comparison uses a string compare on pre-release tags, which means `1.0.1-beta-wip` is considered *newer* than `1.0.1-beta` (alphabetically `beta` < `beta-wip` because `beta-wip` is longer). This is technically wrong semver — a `wip` build should be considered older than the published release of the same version. The consequence: an install running `1.0.1-beta-wip` will not see an "update available" banner pointing at `1.0.1-beta` when it ships.
  - **Workaround for now:** before tagging the real `1.0.1-beta`, bump the WIP version on the dev machine manually (e.g. to `1.0.1-rc.1`) so the comparison works as expected.
  - **Proper fix later:** parse pre-release identifiers as dot-separated parts and apply semver-spec rules (numeric < non-numeric, fewer parts < more parts in identical-prefix comparisons). Track in a separate issue.
