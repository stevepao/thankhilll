<?php
/**
 * POST /push/unsubscribe — Remove one subscription by endpoint for the current user (idempotent).
 *
 * Body: JSON { "endpoint": "...", "csrf_token": "..." } or X-CSRF-Token header.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/push_subscription_repository.php';

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
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

csrf_verify_decoded_json_or_header_or_abort($data);

$endpoint = $data['endpoint'] ?? null;
if (!is_string($endpoint) || trim($endpoint) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'endpoint_required']);
    exit;
}

try {
    $pdo = db();
    push_subscription_delete_by_endpoint_for_user($pdo, $userId, trim($endpoint));
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('push/unsubscribe: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
