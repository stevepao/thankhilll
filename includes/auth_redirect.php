<?php
/**
 * Safe same-origin redirect targets after login (path/query only).
 */
declare(strict_types=1);

function auth_redirect_uri_safe(string $uri): bool
{
    $uri = trim($uri);
    if ($uri === '') {
        return false;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri) === 1) {
        return false;
    }
    if ($uri[0] !== '/') {
        return false;
    }
    if (isset($uri[1]) && $uri[1] === '/') {
        return false;
    }

    return true;
}
