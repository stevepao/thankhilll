<?php
/**
 * includes/assets.php — Cache-busting URLs for static files (plain PHP).
 */
declare(strict_types=1);

/**
 * Append ?v=mtime for site-root paths like /reactions/reactions.js so browsers pick up updates.
 */
function asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (str_contains($path, '..')) {
        return $path[0] === '/' ? $path : '/' . $path;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $root = dirname(__DIR__);
    $fsPath = $root . $path;
    if (!is_file($fsPath)) {
        return $path;
    }

    $mtime = filemtime($fsPath);
    if ($mtime === false) {
        return $path;
    }

    return $path . '?v=' . $mtime;
}
