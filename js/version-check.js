/**
 * Ledger — Update check (client-side, browser → tryledger.dev direct)
 *
 * When a logged-in user has the "Check for updates" setting enabled, this
 * module checks https://tryledger.dev/api/version.json at most once every
 * 24 hours and displays a banner if a newer version is available.
 *
 * No data is sent to Ledger's maintainers beyond what any web server logs
 * for any request — IP address, user agent, timestamp. The endpoint sends
 * no cookies and accepts no parameters. localStorage caches the response
 * and the dismissed-version state.
 *
 * Fails silent on any error so it can't break the admin's session.
 */
(function () {
    'use strict';

    // ─── Configuration ────────────────────────────────────────────────────
    const ENDPOINT         = 'https://tryledger.dev/api/version.json';
    const CACHE_KEY        = 'ledger_version_check';
    const DISMISS_KEY      = 'ledger_version_dismissed';
    const CACHE_TTL_MS     = 24 * 60 * 60 * 1000;
    const FETCH_TIMEOUT_MS = 4000;

    // ─── Read current version from meta tag ───────────────────────────────
    const currentVersion = document
        .querySelector('meta[name="ledger-version"]')?.content?.trim();

    if (!currentVersion) return; // misconfigured — fail silent

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        try {
            const latest = await getLatestVersion();
            if (!latest) return;

            if (!isNewer(latest.version, currentVersion)) return;
            if (isDismissed(latest.version)) return;

            injectStyles();
            renderBanner(latest);
        } catch (err) {
            // Update check must never break the page
            if (window.console) console.debug('[ledger] version check failed:', err);
        }
    }

    // ─── Cache layer ──────────────────────────────────────────────────────
    function readCache() {
        try {
            const raw = localStorage.getItem(CACHE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            if (Date.now() - parsed.fetchedAt > CACHE_TTL_MS) return null;
            return parsed;
        } catch {
            return null;
        }
    }

    function writeCache(data) {
        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify({
                ...data,
                fetchedAt: Date.now(),
            }));
        } catch {
            // localStorage disabled or full — fine, we just refetch next time
        }
    }

    // ─── Fetch from tryledger.dev ─────────────────────────────────────────
    async function getLatestVersion() {
        const cached = readCache();
        if (cached) return cached;

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

        try {
            const res = await fetch(ENDPOINT, {
                headers: { 'Accept': 'application/json' },
                signal: controller.signal,
                // No credentials, no cookies — the endpoint sends none anyway,
                // but be explicit so privacy claims hold under inspection.
                credentials: 'omit',
            });
            clearTimeout(timer);

            if (!res.ok) return null;

            const data = await res.json();
            // Endpoint returns { version, released, url, security, ... }
            // — see https://tryledger.dev/api/version.json
            if (!data || typeof data.version !== 'string') return null;

            const result = {
                version:     data.version,
                url:         data.url || null,
                publishedAt: data.released || null,
                security:    !!data.security,
            };

            writeCache(result);
            return result;
        } catch {
            clearTimeout(timer);
            return null;
        }
    }
    // ─── Version comparison (semver-aware) ────────────────────────────────
    /**
     * Returns true if `a` is a newer version than `b`. Handles pre-release
     * tags per semver rules:
     *   1.0.0-beta < 1.0.0 < 1.0.1
     *   1.0.0-alpha < 1.0.0-beta < 1.0.0-rc.1 < 1.0.0
     */
    function isNewer(a, b) {
        const parse = (v) => {
            const [main, pre] = v.split('-');
            const parts = main.split('.').map((n) => parseInt(n, 10) || 0);
            while (parts.length < 3) parts.push(0);
            return { parts, pre: pre || null };
        };
        const pa = parse(a);
        const pb = parse(b);

        // Compare major.minor.patch numerically
        for (let i = 0; i < 3; i++) {
            if (pa.parts[i] !== pb.parts[i]) return pa.parts[i] > pb.parts[i];
        }

        // Same numeric version — apply pre-release rules.
        // No pre-release outranks any pre-release: 1.0.0 > 1.0.0-beta
        if (!pa.pre && pb.pre)  return true;
        if (pa.pre && !pb.pre)  return false;
        if (!pa.pre && !pb.pre) return false;

        // Both pre-release — string compare (alpha < beta < rc works)
        return pa.pre > pb.pre;
    }

    // ─── Dismissal tracking (per-version) ─────────────────────────────────
    function isDismissed(version) {
        try {
            return localStorage.getItem(DISMISS_KEY) === version;
        } catch {
            return false;
        }
    }

    function dismiss(version) {
        try {
            localStorage.setItem(DISMISS_KEY, version);
        } catch {}
    }

    // ─── UI ────────────────────────────────────────────────────────────────
    function renderBanner({ version, url, security }) {
        const banner = document.createElement('div');
        banner.className = 'ledger-update-banner' + (security ? ' is-security' : '');
        banner.setAttribute('role', 'status');

        // Build DOM via createElement (avoids innerHTML XSS surface for version strings)
        const wrap = document.createElement('div');
        wrap.className = 'ledger-update-banner__content';

        const icon = document.createElement('span');
        icon.className = 'ledger-update-banner__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = security ? '🔒' : '✨';

        const text = document.createElement('span');
        text.className = 'ledger-update-banner__text';
        const label = document.createElement('strong');
        label.textContent = security
            ? 'Security update available: '
            : 'Update available: ';
        const versionCode = document.createElement('code');
        versionCode.textContent = version;
        text.append(label, 'Ledger ', versionCode, ' has been released.');

        const link = document.createElement('a');
        link.className = 'ledger-update-banner__link';
        link.href = url || `https://github.com/${repo}/releases`;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'View release notes →';

        wrap.append(icon, text, link);

        // Dismiss button — only for non-security updates
        if (!security) {
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'ledger-update-banner__close';
            close.setAttribute('aria-label', 'Dismiss until next release');
            close.textContent = '×';
            close.addEventListener('click', () => {
                dismiss(version);
                banner.remove();
            });
            wrap.appendChild(close);
        }

        banner.appendChild(wrap);
        document.body.insertBefore(banner, document.body.firstChild);
    }

    // Inject the banner stylesheet once, theme-variable driven so it
    // inherits from whichever Ledger theme is active.
    function injectStyles() {
        if (document.getElementById('ledger-update-styles')) return;
        const style = document.createElement('style');
        style.id = 'ledger-update-styles';
        style.textContent = `
.ledger-update-banner {
    position: relative;
    background: var(--bg-panel, var(--bg-card, #1a1d28));
    border-bottom: 1px solid var(--border, #2a2f3a);
    border-left: 3px solid var(--accent, #4ade80);
    color: var(--text-primary, #e5e7eb);
    font-size: 0.875rem;
    z-index: 100;
    animation: ledger-banner-in 0.3s ease-out;
}
.ledger-update-banner.is-security {
    border-left-color: var(--warning, #f0a030);
    background: var(--warning-bg, rgba(240, 160, 48, 0.08));
}
.ledger-update-banner__content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0.75rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.ledger-update-banner__icon {
    font-size: 1.1rem;
    line-height: 1;
}
.ledger-update-banner__text {
    flex: 1;
    min-width: 0;
}
.ledger-update-banner__text code {
    font-family: var(--font-code, ui-monospace, monospace);
    background: rgba(255, 255, 255, 0.06);
    padding: 0.1em 0.4em;
    border-radius: 3px;
    font-size: 0.85em;
}
.ledger-update-banner__link {
    color: var(--accent, #4ade80);
    text-decoration: none;
    font-weight: 500;
    white-space: nowrap;
}
.ledger-update-banner__link:hover {
    text-decoration: underline;
}
.ledger-update-banner.is-security .ledger-update-banner__link {
    color: var(--warning, #f0a030);
}
.ledger-update-banner__close {
    background: transparent;
    border: 0;
    color: var(--text-muted, #9ca3af);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    transition: background 0.15s, color 0.15s;
}
.ledger-update-banner__close:hover {
    background: var(--bg-hover, rgba(255, 255, 255, 0.06));
    color: var(--text-primary, #e5e7eb);
}
@keyframes ledger-banner-in {
    from { transform: translateY(-100%); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}
@media (max-width: 640px) {
    .ledger-update-banner__content {
        padding: 0.625rem 1rem;
        font-size: 0.8125rem;
    }
}
`;
        document.head.appendChild(style);
    }
})();
