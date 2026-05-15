# Security Policy

## Reporting a vulnerability

If you find a security issue in Ledger, please **don't open a public GitHub issue**. Security reports filed publicly give attackers a head start before a fix can ship.

Instead, report it privately through one of these channels:

- **GitHub Security Advisories** (preferred): [Open a draft advisory](https://github.com/ClearanceClarence/Ledger/security/advisories/new). This is GitHub's built-in private channel for vulnerability reports.
- **Email**: send to the maintainer's address listed on their [GitHub profile](https://github.com/ClearanceClarence). Encrypt with PGP if you have a key.

Please include:

- A description of the issue
- Steps to reproduce (a working proof-of-concept is welcome but not required)
- The affected version(s)
- Your assessment of the impact

You can expect:

- **Acknowledgement within 5 business days.** If you haven't heard back in a week, ping the same channel — your report may have been missed.
- **A first assessment within 14 days.** This covers whether the issue is reproducible, what the severity looks like, and a rough timeline for a fix.
- **Public disclosure coordinated with you.** Once a fix is available, the advisory is published with credit to you (unless you'd prefer to remain anonymous).

## Scope

In scope:

- The Ledger codebase as published on the main branch and in tagged releases
- The default Docker image and docker-compose configuration
- Authentication, authorization, CSRF, session handling, the installer flow
- SQL injection, XSS, file inclusion, path traversal, and similar in-application classes
- Insecure defaults in shipped configuration

Out of scope:

- Vulnerabilities in PHP itself, MySQL/MariaDB, Apache, nginx, or other infrastructure Ledger runs on
- Issues that require an attacker to already have valid Ledger credentials with administrator rights (those are post-auth issues; the threat model assumes admins can run arbitrary SQL)
- Social engineering attacks against you or maintainers
- Denial of service from arbitrarily large input — Ledger is single-tenant and the user can already run arbitrary queries, so a DoS by the logged-in user against themselves isn't a security boundary

## Supported versions

Only the latest minor release of Ledger receives security fixes. As an early-stage project (currently in public beta), there is no extended support for older versions. Upgrade to the latest release if you're running an older one and a vulnerability has been published.

| Version       | Supported          |
| ------------- | ------------------ |
| 1.0.x-beta    | :white_check_mark: |
| < 1.0.x-beta  | :x:                |

## Security expectations

A few things worth knowing about Ledger's security posture so you can decide whether a finding is in or out of scope:

- Ledger is designed for **trusted local or self-hosted use** by administrators who are expected to have full database access. Once a user logs in as an admin, they can read, write, and drop arbitrary tables. That's by design — it's a database admin tool. Findings that boil down to "an authenticated admin can do something destructive" aren't vulnerabilities.
- Ledger is **not** designed to be exposed to the public internet without additional layers (VPN, reverse proxy with auth, IP allowlist, etc.). Out-of-the-box defaults assume a private deployment.
- TOTP 2FA is optional per-user. It's enabled by default in the installer but admins can disable it. Findings about non-2FA accounts being a weaker target are valid but lower priority than findings about 2FA itself being bypassable.
- CSRF tokens are checked on every state-changing request. Findings about missing CSRF protection on specific endpoints are in scope.

## Responsible disclosure

I'll credit you in the published advisory and in the release notes for the fix. If you have a preferred name, link, or affiliation to be credited under, mention it in your report. If you'd rather stay anonymous, that's also fine — just say so.

Thanks for taking the time to report responsibly.

## Privacy & network behavior

Ledger does not phone home from the server. The installed application makes no outbound network calls from PHP during normal operation.

There is one optional, client-side network call: when a logged-in user is browsing the admin UI with **Check for updates** enabled (default on, opt-out under Settings), their browser makes a `GET` request to `https://tryledger.dev/api/version.json` at most once every 24 hours. This request:

- Goes from the user's browser directly to `tryledger.dev` — not via your server, and not to GitHub.
- Sends no parameters, no cookies, no identifying data. The endpoint reads no input.
- Receives a small JSON response with the latest version number, release date, and a security flag — nothing about your install is exchanged.
- Is subject to `tryledger.dev`'s standard web access logs (IP address, user agent, timestamp), the same as any visit to the marketing site.
- Is cached in the user's browser localStorage for 24 hours.
- Fails silent if the request times out, is rate-limited, or is blocked by a corporate firewall.

The endpoint response format is documented at <https://tryledger.dev/api/version.json> — you can fetch it from a terminal to see exactly what would be returned.

If you'd rather make no outbound requests at all, disable **Check for updates** in Settings. The setting persists in `config.php`.
