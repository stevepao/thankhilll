<?php
/**
 * Signed GET access to note photos for MCP agents (HMAC; short TTL).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/media_signing.php';
require_once dirname(__DIR__) . '/includes/note_media.php';

$idRaw = isset($_GET['id']) ? (string) $_GET['id'] : '';
$variant = isset($_GET['v']) ? (string) $_GET['v'] : 'full';
$exp = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
$sigRaw = isset($_GET['sig']) ? (string) $_GET['sig'] : '';

function mcp_photo_fail(): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Forbidden';
}

if ($idRaw === '' || !ctype_digit($idRaw)) {
    mcp_photo_fail();
    exit;
}

if ($variant !== 'full' && $variant !== 'thumb') {
    mcp_photo_fail();
    exit;
}

if ($exp <= 0 || time() >= $exp) {
    mcp_photo_fail();
    exit;
}

// Reject far-future expiry (links valid at most ~15 minutes from request time).
if ($exp > time() + 900) {
    mcp_photo_fail();
    exit;
}

$photoInt = (int) $idRaw;
if ($photoInt <= 0) {
    mcp_photo_fail();
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    <<<'SQL'
    SELECT nm.file_path, n.user_id
    FROM note_media nm
    INNER JOIN notes n ON n.id = nm.note_id
    WHERE nm.id = ?
    LIMIT 1
    SQL
);
$stmt->execute([$photoInt]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    mcp_photo_fail();
    exit;
}

$userId = (int) ($row['user_id'] ?? 0);
if ($userId <= 0) {
    mcp_photo_fail();
    exit;
}

if (!mcp_verify_signed_media_request($userId, $idRaw, $variant, $exp, $sigRaw)) {
    mcp_photo_fail();
    exit;
}

$relativePath = (string) ($row['file_path'] ?? '');
$abs = note_media_resolve_absolute($relativePath);
if ($abs === null || !is_readable($abs)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$mime = mcp_media_mime_from_relative_path($relativePath);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($abs);
