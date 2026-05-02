<?php
/**
 * HMAC-signed URLs for MCP media viewing (no tokens in query beyond expiry + sig).
 */
declare(strict_types=1);

function mcp_media_signing_secret(): string
{
    return trim((string) ($_ENV['MCP_MEDIA_SIGNING_KEY'] ?? getenv('MCP_MEDIA_SIGNING_KEY') ?: ''));
}

function mcp_media_string_to_sign(int $userId, string $photoId, string $variant, int $exp): string
{
    return $userId . '|' . $photoId . '|' . $variant . '|' . $exp;
}

function mcp_media_signature_hex(int $userId, string $photoId, string $variant, int $exp): string
{
    $secret = mcp_media_signing_secret();
    if ($secret === '') {
        return '';
    }

    return hash_hmac('sha256', mcp_media_string_to_sign($userId, $photoId, $variant, $exp), $secret);
}

function mcp_media_verify_signature(int $userId, string $photoId, string $variant, int $exp, string $sigHex): bool
{
    $secret = mcp_media_signing_secret();
    if ($secret === '' || $sigHex === '') {
        return false;
    }
    $expected = mcp_media_signature_hex($userId, $photoId, $variant, $exp);
    if ($expected === '') {
        return false;
    }

    return hash_equals($expected, strtolower(trim($sigHex)));
}

/**
 * Absolute site origin (no trailing slash). Prefer APP_BASE_URL from env.
 */
function mcp_public_base_url(): string
{
    $base = trim((string) ($_ENV['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
        ? $_SERVER['HTTP_HOST']
        : 'localhost';

    return $scheme . '://' . $host;
}

/**
 * Full signed GET URL for /mcp/photo.php (uses APP_BASE_URL or current request host).
 *
 * @param 'full'|'thumb' $variant
 */
function mcp_make_signed_media_url(int $userId, string $photoId, string $variant, int $ttlSeconds): string
{
    return mcp_build_signed_photo_url(mcp_public_base_url(), $userId, $photoId, $variant, $ttlSeconds);
}

/**
 * @param 'full'|'thumb' $variant
 */
function mcp_build_signed_photo_url(string $baseUrl, int $userId, string $photoId, string $variant, int $ttlSeconds): string
{
    $ttlSeconds = max(1, min($ttlSeconds, 900));
    $exp = time() + $ttlSeconds;
    $sig = mcp_media_signature_hex($userId, $photoId, $variant, $exp);
    if ($sig === '') {
        return '';
    }

    $q = http_build_query([
        'id' => $photoId,
        'v' => $variant,
        'exp' => $exp,
        'sig' => $sig,
    ]);

    return rtrim($baseUrl, '/') . '/mcp/photo.php?' . $q;
}

function mcp_verify_signed_media_request(int $userId, string $photoId, string $variant, int $exp, string $sig): bool
{
    return mcp_media_verify_signature($userId, $photoId, $variant, $exp, $sig);
}

function mcp_media_mime_from_relative_path(string $relativePath): string
{
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    return $ext === 'png' ? 'image/png' : 'image/jpeg';
}
