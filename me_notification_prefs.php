<?php
/**
 * POST — Save comment-reply push preference (JSON). Used by Me tab toggle (fetch).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/user_notification_prefs_repository.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$userId = current_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

csrf_verify_json_or_header_or_abort();

$raw = file_get_contents('php://input');
$data = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'json']);
    exit;
}

$flag = $data['push_comment_replies_enabled'] ?? null;
if (!is_bool($flag)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'push_comment_replies_enabled']);
    exit;
}

$pdo = db();
$prefs = user_notification_prefs_get($pdo, $userId);
$reminders = $prefs['push_reminders_enabled'];
$pushEnabled = $flag || $reminders;

try {
    user_notification_prefs_save($pdo, $userId, $pushEnabled, $reminders, $flag);
} catch (Throwable $e) {
    error_log('me_notification_prefs: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}

echo json_encode([
    'ok' => true,
    'push_comment_replies_enabled' => $flag,
    'push_enabled' => $pushEnabled,
], JSON_UNESCAPED_SLASHES);
