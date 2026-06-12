<?php
/**
 * Ledger — Update Check
 *
 * Server-side proxy for the tryledger.dev/api/version.json endpoint.
 * Replaces the previous direct browser → tryledger.dev fetch which was
 * fragile (CORS issues, per-browser caching, every user's IP hit the CDN).
 *
 * The flow:
 *   1. Browser → ajax.php?action=check_update
 *   2. PHP reads logs/version-cache.json
 *   3a. If cache fresh (<24h) → return cached data
 *   3b. If cache stale → fetch tryledger.dev server-side, save, return
 *   4. On any network error → keep serving the last good cache
 *
 * Privacy contract preserved: PHP sends no cookies, no parameters, no
 * referer to the upstream endpoint. The only data leaving the install is
 * what any HTTP request reveals (IP, user agent, timestamp). And now it's
 * one request per install per day instead of one per browser per day —
 * a meaningful privacy improvement.
 *
 * Failure modes handled honestly:
 *   - allow_url_fopen=Off AND no cURL → return error, no banner shown
 *   - tryledger.dev down → keep serving stale cache, mark as stale
 *   - logs/ not writable → return fresh data without caching
 *   - upstream returns invalid JSON → don't poison cache, return last good
 *   - upstream returns HTTP 5xx → keep serving stale cache
 */

class UpdateCheck
{
    /** Where the cached upstream response lives on disk. */
    private const CACHE_FILE = __DIR__ . '/../logs/version-cache.json';

    /** Upstream endpoint to fetch. */
    private const ENDPOINT = 'https://tryledger.dev/api/version.json';

    /** Time-to-live for cache entries (24 hours, matches the prior JS TTL). */
    private const CACHE_TTL_SECONDS = 86400;

    /** Network timeout when fetching upstream (4s; non-blocking-ish for UI). */
    private const FETCH_TIMEOUT_SECONDS = 4;

    /** User-Agent we present to the upstream — identifies the client honestly. */
    private const USER_AGENT = 'Ledger-UpdateCheck/1.0';

    /**
     * Main entrypoint. Returns an associative array shaped like:
     *   {
     *     "version": "1.0.2-beta",
     *     "released": "2026-05-19",
     *     "url": "https://github.com/.../releases/tag/v1.0.2-beta",
     *     "notes_url": "https://tryledger.dev/changelog",
     *     "security": false,
     *     "fetched_at": 1748373240,   // unix timestamp of when we got it
     *     "cached": true|false,       // true if served from cache, false if just fetched
     *     "stale": false              // true if serving stale cache because upstream is down
     *   }
     * On total failure (no cache, no upstream, no cURL/fopen):
     *   { "error": "unavailable" }
     */
    public static function run(array $config): array
    {
        // Allow disabling via config — same setting as the existing toggle.
        if (empty($config['app']['version_check'] ?? true)) {
            return ['error' => 'disabled'];
        }

        $cache = self::readCache();

        // Cache hit — serve immediately, don't even call upstream
        if ($cache !== null && self::isFresh($cache)) {
            $cache['cached'] = true;
            $cache['stale'] = false;
            return $cache;
        }

        // Cache miss or stale — try to refresh from upstream
        $fresh = self::fetchUpstream();

        if ($fresh !== null) {
            // Got a valid response — cache it (best effort) and return it
            $fresh['fetched_at'] = time();
            self::writeCache($fresh); // failure here is non-fatal
            $fresh['cached'] = false;
            $fresh['stale'] = false;
            return $fresh;
        }

        // Upstream fetch failed — fall back to stale cache if we have one
        if ($cache !== null) {
            $cache['cached'] = true;
            $cache['stale'] = true;
            return $cache;
        }

        // No cache, no upstream — totally unavailable
        return ['error' => 'unavailable'];
    }

    /**
     * Returns the parsed cache file contents, or null if the file is
     * missing, unreadable, or contains malformed JSON.
     */
    private static function readCache(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) return null;

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) return null;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;
        if (!isset($decoded['version']) || !isset($decoded['fetched_at'])) return null;

        return $decoded;
    }

    /**
     * Returns true if the cache was fetched within the TTL window.
     * A cache without a fetched_at timestamp is treated as stale.
     */
    private static function isFresh(array $cache): bool
    {
        $age = time() - (int)($cache['fetched_at'] ?? 0);
        return $age >= 0 && $age < self::CACHE_TTL_SECONDS;
    }

    /**
     * Writes the cache file. Best-effort — failures are silent because the
     * caller will still return the fresh data; we just lose the cache for
     * this cycle.
     */
    private static function writeCache(array $data): void
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;

        // Atomic write: write to temp then rename so a partial write never
        // leaves a malformed cache file that breaks subsequent reads.
        $tmp = self::CACHE_FILE . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, self::CACHE_FILE);
        }
    }

    /**
     * Fetch the upstream endpoint and return parsed JSON. Returns null on
     * any failure (timeout, HTTP error, invalid JSON, network unreachable).
     *
     * Tries cURL first (more reliable on shared hosts that limit fopen),
     * falls back to file_get_contents with a stream context if cURL is
     * unavailable.
     */
    private static function fetchUpstream(): ?array
    {
        $body = self::fetchViaCurl() ?? self::fetchViaFopen();
        if ($body === null) return null;

        $parsed = json_decode($body, true);
        if (!is_array($parsed)) return null;

        // Validate the expected shape — if the upstream changes its contract
        // we'd rather show nothing than show a malformed banner.
        if (!isset($parsed['version']) || !is_string($parsed['version'])) {
            return null;
        }

        // Pick only the fields we care about. Don't store anything we don't
        // explicitly know about — the cache is documented and stable.
        return [
            'version'   => $parsed['version'],
            'released'  => isset($parsed['released'])  && is_string($parsed['released'])  ? $parsed['released']  : null,
            'url'       => isset($parsed['url'])       && is_string($parsed['url'])       ? $parsed['url']       : null,
            'notes_url' => isset($parsed['notes_url']) && is_string($parsed['notes_url']) ? $parsed['notes_url'] : null,
            'security'  => !empty($parsed['security']),
        ];
    }

    /**
     * Fetch via cURL. Returns the response body string, or null on failure.
     */
    private static function fetchViaCurl(): ?string
    {
        if (!function_exists('curl_init')) return null;

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // Be explicit: don't send cookies, don't send referer. The
            // privacy contract is documented in api/README.md.
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_REFERER        => '',
            // SSL: verify properly. If a user's hosting can't verify
            // tryledger.dev's certificate they have bigger problems.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) return null;
        if ($status < 200 || $status >= 300) return null;
        return $body;
    }

    /**
     * Fetch via file_get_contents with a stream context. Used when cURL
     * isn't installed. Requires allow_url_fopen=On in php.ini.
     */
    private static function fetchViaFopen(): ?string
    {
        if (!ini_get('allow_url_fopen')) return null;

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::FETCH_TIMEOUT_SECONDS,
                'header'        => [
                    'Accept: application/json',
                    'User-Agent: ' . self::USER_AGENT,
                ],
                'ignore_errors' => true,  // we want the body even on 4xx/5xx so we can decide
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents(self::ENDPOINT, false, $context);
        if ($body === false) return null;

        // Check status from the magic $http_response_header array PHP sets
        // when file_get_contents talks HTTP.
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($status < 200 || $status >= 300) return null;

        return $body;
    }
}
