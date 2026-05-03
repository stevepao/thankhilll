<?php
/**
 * Authenticated download for a completed user data export ZIP.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/user_export.php';

$userId = require_login();
$pdo = db();

$exportId = isset($_GET['export_id']) ? (int) $_GET['export_id'] : 0;
if ($exportId <= 0) {
    http_response_code(400);
    echo 'Bad request';

    exit;
}

$stmt = $pdo->prepare(
    'SELECT file_path FROM user_data_exports WHERE id = ? AND user_id = ? AND status = ? LIMIT 1'
);
$stmt->execute([$exportId, $userId, 'ready']);
$rel = $stmt->fetchColumn();
if (!is_string($rel) || $rel === '') {
    http_response_code(404);
    echo 'Export not found or not ready';

    exit;
}

$abs = user_export_resolve_absolute_zip($rel);
if ($abs === null) {
    http_response_code(404);
    echo 'File missing';

    exit;
}

$basename = basename($abs);
$size = filesize($abs);
if ($size === false) {
    http_response_code(500);
    echo 'Could not read file';

    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($basename) . '"');
header('Content-Length: ' . (string) $size);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');

readfile($abs);
exit;
