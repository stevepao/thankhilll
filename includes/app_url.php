<?php
/**
 * Absolute URLs for links in push payloads and external redirects.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/**
 * Site origin for push notification URLs (optional APP_BASE_URL in .env, else current request).
 */
function app_absolute_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $base = trim(env_var('APP_BASE_URL'));
    if ($base !== '') {
        return rtrim($base, '/') . $path;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    return $scheme . '://' . $host . $path;
}
