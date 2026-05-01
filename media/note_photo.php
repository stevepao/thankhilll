<?php
/**
 * media/note_photo.php — Serve a note photo only if the viewer may read the note.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/note_access.php';

$userId = require_login();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT nm.file_path, nm.note_id FROM note_media nm WHERE nm.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    http_response_code(404);
    exit;
}

$noteId = (int) $row['note_id'];
if (!user_can_view_note($pdo, $userId, $noteId)) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/note_media.php';

$abs = note_media_resolve_absolute((string) $row['file_path']);
if ($abs === null) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($abs);
