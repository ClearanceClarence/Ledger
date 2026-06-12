/**
 * Ledger — Update Check (client side)
 *
 * Hits the same-origin update-check proxy at ajax.php?action=check_update.
 * The proxy handles fetching tryledger.dev and caching for 24 hours; this
 * script just renders the banner if a newer version exists.
 *
 * Same-origin means: no CORS, no per-browser cache duplication, no third-
 * party request from the user's browser. Privacy and reliability both
 * improve compared to the previous direct-fetch implementation.
 *
 * Fails silent on any error so it can never break the admin session.
 */
(function () {
    'use strict';

    // ─── Configuration ────────────────────────────────────────────────────
    const PROXY_ENDPOINT   = 'ajax.php?action=check_update';
    const DISMISS_KEY      = 'ledger_version_dismissed';
    const FETCH_TIMEOUT_MS = 6000; // proxy might do its own upstream fetch

    // ─── Read current version from meta tag ───────────────────────────────
    const currentVersion = document
        .querySelector('meta[name="ledger-version"]')?.content?.trim();

    if (!currentVersion) return; // misconfigured — fail silent

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        try {
            const latest = await fetchFromProxy();
            if (!latest) return;
            if (latest.error) return;
            if (!latest.version) return;

            if (!isNewer(latest.version, currentVersion)) return;
            if (isDismissed(latest.version)) return;

            injectStyles();
            renderBanner(latest);
        } catch (err) {
            // Update check must never break the page
            if (window.console) console.debug('[ledger] version check failed:', err);
        }
    }

    // ─── Fetch from the same-origin proxy ─────────────────────────────────
    async function fetchFromProxy() {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
        try {
            const res = await fetch(PROXY_ENDPOINT, {
                headers: { 'Accept': 'application/json' },
                signal: controller.signal,
                // Same-origin — let cookies through so the auth check in
                // ajax.php sees the session. Without this, the endpoint
                // would 401 and the banner never appears.
                credentials: 'same-origin',
            });
            clearTimeout(timer);
            if (!res.ok) return null;
            return await res.json();
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

        // Both pre-release — split on dots and compare segment-by-segment.
        // Numeric segments compare numerically; mixed use string compare.
        // This makes 1.0.0-rc.10 > 1.0.0-rc.2 (which a plain string compare
        // would get wrong).
        const sa = pa.pre.split('.');
        const sb = pb.pre.split('.');
        const len = Math.max(sa.length, sb.length);
        for (let i = 0; i < len; i++) {
            const ai = sa[i];
            const bi = sb[i];
            if (ai === undefined) return false; // shorter is older
            if (bi === undefined) return true;  // longer is newer
            const an = /^\d+$/.test(ai);
            const bn = /^\d+$/.test(bi);
            if (an && bn) {
                const n1 = parseInt(ai, 10);
                const n2 = parseInt(bi, 10);
                if (n1 !== n2) return n1 > n2;
            } else {
                if (ai !== bi) return ai > bi;
            }
        }
        return false;
    }

    // ─── Dismissal tracking (per-version, browser-local) ──────────────────
    // localStorage is still the right place for "this user dismissed this
    // version on this browser". Per-user preference, not per-install state.
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
    // Renders a small toast in the top-right corner. Doesn't push content
    // down, doesn't auto-dismiss (new releases aren't time-critical
    // acknowledgments — they're information the user decides what to do
    // with). Dismissal is per-version: dismissing 1.0.4 doesn't suppress
    // 1.0.5 when that ships.
    //
    // Security updates get a stronger amber treatment and don't show a
    // dismiss button — same rule as the previous banner.
    function renderBanner({ version, url, notes_url, security }) {
        const toast = document.createElement('div');
        toast.className = 'ledger-update-toast' + (security ? ' is-security' : '');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');

        // ─── Header row: label + dismiss button ─────────────────────────
        const head = document.createElement('div');
        head.className = 'ledger-update-toast__head';

        const label = document.createElement('span');
        label.className = 'ledger-update-toast__label';
        // Tiny dot lets people pick out the toast from peripheral vision
        // without needing to read the text. Color shifts with severity.
        const dot = document.createElement('span');
        dot.className = 'ledger-update-toast__dot';
        dot.setAttribute('aria-hidden', 'true');
        const labelText = document.createElement('span');
        labelText.textContent = security ? 'Security update' : 'Update available';
        label.append(dot, labelText);
        head.appendChild(label);

        // Dismiss × — only for non-security updates
        if (!security) {
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'ledger-update-toast__close';
            close.setAttribute('aria-label', 'Dismiss until next release');
            close.textContent = '×';
            close.addEventListener('click', () => {
                dismiss(version);
                // Match the entrance: slide out + fade before removing
                toast.classList.add('is-leaving');
                setTimeout(() => toast.remove(), 200);
            });
            head.appendChild(close);
        }

        toast.appendChild(head);

        // ─── Body: version line ─────────────────────────────────────────
        const body = document.createElement('div');
        body.className = 'ledger-update-toast__body';
        body.append('Ledger ');
        const versionCode = document.createElement('code');
        versionCode.textContent = version;
        body.append(versionCode, ' is out.');
        toast.appendChild(body);

        // ─── Footer: link to release notes ──────────────────────────────
        // Prefer release-notes URL (changelog), fall back to GitHub release
        // page. If both missing, omit the link rather than crash — the
        // old direct-fetch code had a ReferenceError on this path.
        const href = url || notes_url;
        if (href) {
            const link = document.createElement('a');
            link.className = 'ledger-update-toast__link';
            link.href = href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'View release notes →';
            toast.appendChild(link);
        }

        document.body.appendChild(toast);
        // Use rAF + a class swap to trigger the entrance transition. Without
        // this, the toast appears in its final state instead of animating in.
        requestAnimationFrame(() => toast.classList.add('is-visible'));
    }

    // Inject the toast stylesheet once, theme-variable driven so it
    // inherits from whichever Ledger theme is active.
    function injectStyles() {
        if (document.getElementById('ledger-update-styles')) return;
        const style = document.createElement('style');
        style.id = 'ledger-update-styles';
        style.textContent = `
.ledger-update-toast {
    position: fixed;
    /* Sits below the app header (~50-56px tall) with a comfortable gap.
       Doesn't overlap the connection/admin/logout cluster in the top-right. */
    top: 72px;
    right: 1rem;
    z-index: 9999;
    width: 280px;
    max-width: calc(100vw - 2rem);
    padding: 0.85rem 1rem 0.95rem;
    background: var(--bg-panel, var(--bg-card, #13131c));
    border: 1px solid var(--border, #1c1c28);
    border-radius: 8px;
    box-shadow:
        0 12px 28px rgba(0, 0, 0, 0.35),
        0 4px 8px rgba(0, 0, 0, 0.18);
    color: var(--text-primary, #d8d8e4);
    font-size: 0.8125rem;
    line-height: 1.45;
    /* Slide in from the right + fade. Transform stays at translateX(8px)
       until .is-visible swaps it. */
    transform: translateX(8px);
    opacity: 0;
    transition: transform 220ms cubic-bezier(0.16, 1, 0.3, 1),
                opacity 180ms ease-out;
}
.ledger-update-toast.is-visible {
    transform: translateX(0);
    opacity: 1;
}
.ledger-update-toast.is-leaving {
    transform: translateX(8px);
    opacity: 0;
    transition-duration: 160ms;
}
@media (prefers-reduced-motion: reduce) {
    .ledger-update-toast,
    .ledger-update-toast.is-visible,
    .ledger-update-toast.is-leaving {
        transition: opacity 100ms linear;
        transform: none;
    }
}

/* ─── Header row: label + dismiss ────────────────────────────── */
.ledger-update-toast__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.45rem;
}
.ledger-update-toast__label {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted, #6b6e80);
}
.ledger-update-toast__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent, #4ade80);
    box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.18);
    /* Subtle pulse, like the server-status chip in the app header */
    animation: ledger-toast-pulse 2.8s ease-in-out infinite;
}
@keyframes ledger-toast-pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.18); }
    50%      { box-shadow: 0 0 0 4px rgba(74, 222, 128, 0.04); }
}
@media (prefers-reduced-motion: reduce) {
    .ledger-update-toast__dot { animation: none; }
}

.ledger-update-toast__close {
    background: transparent;
    border: 0;
    color: var(--text-muted, #6b6e80);
    font-size: 1.125rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.3rem;
    margin: -0.2rem -0.2rem -0.2rem 0;
    border-radius: 4px;
    transition: background 120ms ease, color 120ms ease;
}
.ledger-update-toast__close:hover {
    background: var(--bg-hover, rgba(255, 255, 255, 0.06));
    color: var(--text-primary, #d8d8e4);
}
.ledger-update-toast__close:focus-visible {
    outline: 2px solid var(--accent, #4ade80);
    outline-offset: 1px;
}

/* ─── Body: "Ledger X.Y.Z is out." ────────────────────────────── */
.ledger-update-toast__body {
    color: var(--text-primary, #d8d8e4);
    font-size: 0.875rem;
    margin-bottom: 0.55rem;
}
.ledger-update-toast__body code {
    font-family: var(--font-mono, ui-monospace, "JetBrains Mono", monospace);
    background: var(--bg-hover, rgba(255, 255, 255, 0.06));
    padding: 0.1em 0.4em;
    border-radius: 3px;
    font-size: 0.85em;
    font-weight: 500;
    color: var(--accent, #4ade80);
}

/* ─── Link: "View release notes →" ─────────────────────────── */
.ledger-update-toast__link {
    display: inline-block;
    color: var(--accent, #4ade80);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.8125rem;
    transition: color 120ms ease;
}
.ledger-update-toast__link:hover {
    text-decoration: underline;
    text-underline-offset: 2px;
}
.ledger-update-toast__link:focus-visible {
    outline: 2px solid var(--accent, #4ade80);
    outline-offset: 2px;
    border-radius: 2px;
}

/* ─── Security variant ────────────────────────────────────────── */
/* Stronger visual treatment for security updates: amber border, amber
   dot, amber link, amber version chip. No dismiss button (handled in
   JS — we don't render it at all for security updates). */
.ledger-update-toast.is-security {
    border-color: var(--warning, #f0a030);
    box-shadow:
        0 12px 28px rgba(240, 160, 48, 0.18),
        0 4px 8px rgba(0, 0, 0, 0.18);
}
.ledger-update-toast.is-security .ledger-update-toast__dot {
    background: var(--warning, #f0a030);
    box-shadow: 0 0 0 2px rgba(240, 160, 48, 0.22);
    animation-name: ledger-toast-pulse-warning;
}
@keyframes ledger-toast-pulse-warning {
    0%, 100% { box-shadow: 0 0 0 2px rgba(240, 160, 48, 0.22); }
    50%      { box-shadow: 0 0 0 4px rgba(240, 160, 48, 0.06); }
}
.ledger-update-toast.is-security .ledger-update-toast__body code,
.ledger-update-toast.is-security .ledger-update-toast__link {
    color: var(--warning, #f0a030);
}

/* ─── Mobile: pin top edge, slightly less padding ─────────────── */
@media (max-width: 480px) {
    .ledger-update-toast {
        top: 60px;
        right: 0.6rem;
        width: auto;
        max-width: calc(100vw - 1.2rem);
        padding: 0.7rem 0.85rem 0.8rem;
    }
}
`;
        document.head.appendChild(style);
    }
})();
