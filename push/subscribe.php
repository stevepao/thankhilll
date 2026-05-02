<?php
/**
 * POST /push/subscribe — Store or refresh a Web Push subscription (authenticated).
 *
 * Body: JSON PushSubscription shape + optional csrf_token / userAgent / user_agent.
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

$endpoint = trim($endpoint);

$keys = $data['keys'] ?? null;
if (!is_array($keys)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'keys_required']);
    exit;
}

$p256dh = $keys['p256dh'] ?? null;
$authKey = $keys['auth'] ?? null;
if (!is_string($p256dh) || trim($p256dh) === '' || !is_string($authKey) || trim($authKey) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'keys_invalid']);
    exit;
}

$p256dh = trim($p256dh);
$authKey = trim($authKey);

$expRaw = $data['expirationTime'] ?? $data['expiration_time'] ?? null;
$expirationMs = null;
if ($expRaw !== null && $expRaw !== '' && is_numeric($expRaw)) {
    $expirationMs = (int) round((float) $expRaw);
    if ($expirationMs < 0) {
        $expirationMs = null;
    }
}

$userAgent = null;
if (isset($data['userAgent']) && is_string($data['userAgent'])) {
    $ua = trim($data['userAgent']);
    if ($ua !== '') {
        $userAgent = function_exists('mb_substr')
            ? mb_substr($ua, 0, 512, 'UTF-8')
            : substr($ua, 0, 512);
    }
} elseif (isset($data['user_agent']) && is_string($data['user_agent'])) {
    $ua = trim($data['user_agent']);
    if ($ua !== '') {
        $userAgent = function_exists('mb_substr')
            ? mb_substr($ua, 0, 512, 'UTF-8')
            : substr($ua, 0, 512);
    }
}

try {
    $pdo = db();
    $id = push_subscription_upsert(
        $pdo,
        $userId,
        $endpoint,
        $p256dh,
        $authKey,
        $expirationMs,
        $userAgent
    );
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('push/subscribe: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
