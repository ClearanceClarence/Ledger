# WIP Changelog — v1.0.1-beta-wip

> **Status:** Work in progress. Not a published release.
>
> This file collects changes made on top of [v1.0.0-beta](CHANGELOG.md) that haven't been cut into a tagged release yet. When v1.0.1-beta (or v1.0.0 stable) is ready to ship, these notes get moved into `CHANGELOG.md` and this file is reset.

Notes are dated when added so it's clear what's been changing.

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
