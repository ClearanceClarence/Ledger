<?php

/**
 * Scan themes directory and return array of {key, name, variant, style_path}.
 */
function ledger_load_themes(string $themesDir, string $defaultTheme): array
{
    $themes = [];
    foreach (glob($themesDir . '/*/theme.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $slug = basename(dirname($file));
            $data['slug'] = $slug;
            $data['css_path'] = "themes/{$slug}/style.css";
            $themes[$slug] = $data;
        }
    }

    // Determine active theme from cookie or default
    $active = $_COOKIE['ledger_theme'] ?? $defaultTheme;
    if (!isset($themes[$active])) {
        $active = $defaultTheme;
    }

    return [
        'list'   => $themes,
        'active' => $active,
        'data'   => $themes[$active] ?? [],
    ];
}

function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function format_number($num): string
{
    return number_format((int) $num, 0, '.', ',');
}

function format_uptime(int $seconds): string
{
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins = floor(($seconds % 3600) / 60);
    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($mins > 0) $parts[] = "{$mins}m";
    return implode(' ', $parts) ?: '< 1m';
}

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function truncate(string $str, int $len = 60): string
{
    if (mb_strlen($str) <= $len) return $str;
    return mb_substr($str, 0, $len) . '…';
}

/**
 * Return a CSS class for a data cell based on its value (null, numeric, empty).
 */
function cell_class($value, string $columnKey = ''): string
{
    if ($value === null) return 'cell-null';
    if ($columnKey === 'PRI') return 'cell-primary';
    if (is_numeric($value)) return 'cell-number';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) return 'cell-date';
    if (preg_match('/^\$2[ayb]\$/', $value)) return 'cell-hash';
    return '';
}

function input(string $key, $default = null)
{
    return $_GET[$key] ?? $_POST[$key] ?? $default;
}

function ledger_time_ago(int $timestamp): string
{
    $diff = time() - $timestamp;
    if ($diff < 5) return 'just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $timestamp);
}

/**
 * Font catalog — curated fonts grouped by type.
 * 'google' = true means it needs Google Fonts loading.
 */
function ledger_font_catalog(): array
{
    return [
        'sans' => [
            ''                  => ['label' => 'Theme default', 'google' => false],
            'DM Sans'           => ['label' => 'DM Sans', 'google' => true, 'weights' => '400;500;600;700'],
            'Inter'             => ['label' => 'Inter', 'google' => true, 'weights' => '400;500;600;700'],
            'Nunito Sans'       => ['label' => 'Nunito Sans', 'google' => true, 'weights' => '400;600;700'],
            'Open Sans'         => ['label' => 'Open Sans', 'google' => true, 'weights' => '400;600;700'],
            'Lato'              => ['label' => 'Lato', 'google' => true, 'weights' => '400;700'],
            'Roboto'            => ['label' => 'Roboto', 'google' => true, 'weights' => '400;500;700'],
            'Source Sans 3'     => ['label' => 'Source Sans 3', 'google' => true, 'weights' => '400;600;700'],
            'Outfit'            => ['label' => 'Outfit', 'google' => true, 'weights' => '400;600;700'],
            'Sora'              => ['label' => 'Sora', 'google' => true, 'weights' => '400;600;700'],
            'Work Sans'         => ['label' => 'Work Sans', 'google' => true, 'weights' => '400;500;600;700'],
            'Poppins'           => ['label' => 'Poppins', 'google' => true, 'weights' => '400;500;600;700'],
            'IBM Plex Sans'     => ['label' => 'IBM Plex Sans', 'google' => true, 'weights' => '400;500;600;700'],
            'system-ui'         => ['label' => 'System UI', 'google' => false],
            'Segoe UI'          => ['label' => 'Segoe UI (Windows)', 'google' => false],
            '-apple-system'     => ['label' => 'San Francisco (macOS)', 'google' => false],
        ],
        'mono' => [
            ''                  => ['label' => 'Theme default', 'google' => false],
            'JetBrains Mono'    => ['label' => 'JetBrains Mono', 'google' => true, 'weights' => '400;500;600;700'],
            'Fira Code'         => ['label' => 'Fira Code', 'google' => true, 'weights' => '400;500;600;700'],
            'Source Code Pro'   => ['label' => 'Source Code Pro', 'google' => true, 'weights' => '400;500;600;700'],
            'IBM Plex Mono'     => ['label' => 'IBM Plex Mono', 'google' => true, 'weights' => '400;500;600;700'],
            'Roboto Mono'       => ['label' => 'Roboto Mono', 'google' => true, 'weights' => '400;500;700'],
            'Inconsolata'       => ['label' => 'Inconsolata', 'google' => true, 'weights' => '400;600;700'],
            'Space Mono'        => ['label' => 'Space Mono', 'google' => true, 'weights' => '400;700'],
            'Ubuntu Mono'       => ['label' => 'Ubuntu Mono', 'google' => true, 'weights' => '400;700'],
            'Cascadia Code'     => ['label' => 'Cascadia Code (local)', 'google' => false],
            'Consolas'          => ['label' => 'Consolas (Windows)', 'google' => false],
            'Monaco'            => ['label' => 'Monaco (macOS)', 'google' => false],
            'monospace'         => ['label' => 'System monospace', 'google' => false],
        ],
    ];
}

function ledger_font_zones(): array
{
    return [
        'general' => ['label' => 'General UI',       'css_var' => '--font-body',    'catalog' => 'sans', 'desc' => 'Body text, labels, buttons, menus'],
        'heading' => ['label' => 'Headings',          'css_var' => '--font-heading', 'catalog' => 'sans', 'desc' => 'Section titles, page headers'],
        'sidebar' => ['label' => 'Sidebar',           'css_var' => '--font-sidebar', 'catalog' => 'sans', 'desc' => 'Database/table names in the sidebar'],
        'data'    => ['label' => 'Table Data',        'css_var' => '--font-data',    'catalog' => 'mono', 'desc' => 'Cell values in data tables'],
        'code'    => ['label' => 'SQL / Code',        'css_var' => '--font-mono',    'catalog' => 'mono', 'desc' => 'SQL editor, code blocks, monospace text'],
    ];
}

/**
 * Build the Google Fonts <link> URL and CSS overrides from font config.
 * Returns ['link' => '<link ...>' or '', 'css' => 'style block content']
 */
function ledger_font_styles(array $fontConfig): array
{
    $catalog = ledger_font_catalog();
    $zones = ledger_font_zones();
    $googleFamilies = [];
    $cssOverrides = [];

    foreach ($zones as $key => $zone) {
        $fontName = $fontConfig[$key] ?? '';
        if (empty($fontName)) continue;

        $catKey = $zone['catalog']; // 'sans' or 'mono'
        $fontInfo = $catalog[$catKey][$fontName] ?? null;
        if (!$fontInfo) continue;

        // Build CSS value
        $fallback = ($catKey === 'mono') ? ', monospace' : ', system-ui, sans-serif';
        $cssVal = (strpos($fontName, '-') !== false || strpos($fontName, ' ') !== false || ctype_lower($fontName[0] ?? 'A'))
            ? $fontName . $fallback
            : "'{$fontName}'" . $fallback;

        $cssOverrides[] = "    {$zone['css_var']}: {$cssVal};";

        // Track Google Fonts to load
        if (!empty($fontInfo['google'])) {
            $weights = $fontInfo['weights'] ?? '400;700';
            $family = str_replace(' ', '+', $fontName) . ':wght@' . $weights;
            $googleFamilies[$family] = true;
        }
    }

    $link = '';
    if (!empty($googleFamilies)) {
        $families = implode('&family=', array_keys($googleFamilies));
        $link = '<link href="https://fonts.googleapis.com/css2?family=' . $families . '&display=swap" rel="stylesheet">';
    }

    $css = '';
    if (!empty($cssOverrides)) {
        $css = ":root {\n" . implode("\n", $cssOverrides) . "\n}";
    }

    return ['link' => $link, 'css' => $css];
}
