<?php
/**
 * POST — Save push notification prefs (JSON). Used by Me tab toggles (fetch).
 *
 * Accepts optional keys (merge with stored prefs): push_comment_replies_enabled,
 * push_reminders_enabled (daily gratitude reminders). At least one key required per request.
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

if (!array_key_exists('push_comment_replies_enabled', $data) && !array_key_exists('push_reminders_enabled', $data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_prefs']);
    exit;
}

$pdo = db();
$prefs = user_notification_prefs_get($pdo, $userId);

$comments = $prefs['push_comment_replies_enabled'];
if (array_key_exists('push_comment_replies_enabled', $data)) {
    $c = $data['push_comment_replies_enabled'];
    if (!is_bool($c)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'push_comment_replies_enabled']);
        exit;
    }
    $comments = $c;
}

$reminders = $prefs['push_reminders_enabled'];
if (array_key_exists('push_reminders_enabled', $data)) {
    $r = $data['push_reminders_enabled'];
    if (!is_bool($r)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'push_reminders_enabled']);
        exit;
    }
    $reminders = $r;
}

$pushEnabled = $comments || $reminders;

try {
    user_notification_prefs_save($pdo, $userId, $pushEnabled, $reminders, $comments);
} catch (Throwable $e) {
    error_log('me_notification_prefs: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}

echo json_encode([
    'ok' => true,
    'push_comment_replies_enabled' => $comments,
    'push_reminders_enabled' => $reminders,
    'push_enabled' => $pushEnabled,
], JSON_UNESCAPED_SLASHES);
