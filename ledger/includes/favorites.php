<?php
/**
 * Ledger — Favorites (per-user starred tables)
 */


function ledger_favorites_file(): string
{
    return __DIR__ . '/../logs/favorites.json';
}

function ledger_favorites_load(): array
{
    $file = ledger_favorites_file();
    if (!file_exists($file)) return [];
    $data = @file_get_contents($file);
    if ($data === false) return [];
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function ledger_favorites_save(array $all): bool
{
    $file = ledger_favorites_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return (bool)@file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
}

function ledger_favorites_get(string $username): array
{
    $all = ledger_favorites_load();
    $list = $all[$username] ?? [];
    // Normalize to ensure each entry has db + table
    return array_values(array_filter($list, function ($item) {
        return is_array($item) && !empty($item['db']) && !empty($item['table']);
    }));
}

function ledger_favorites_has(string $username, string $db, string $table): bool
{
    foreach (ledger_favorites_get($username) as $fav) {
        if ($fav['db'] === $db && $fav['table'] === $table) return true;
    }
    return false;
}

function ledger_favorites_toggle(string $username, string $db, string $table): bool
{
    $all = ledger_favorites_load();
    $list = $all[$username] ?? [];
    $found = false;
    foreach ($list as $i => $fav) {
        if (isset($fav['db'], $fav['table']) && $fav['db'] === $db && $fav['table'] === $table) {
            array_splice($list, $i, 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $list[] = ['db' => $db, 'table' => $table, 'added' => time()];
    }
    $all[$username] = array_values($list);
    ledger_favorites_save($all);
    return !$found; // true = now favorited, false = now unfavorited
}
