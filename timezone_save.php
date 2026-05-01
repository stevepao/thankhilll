<?php
/**
 * Persist browser IANA time zone (JSON POST). Timestamps stay UTC in DB.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/user_timezone.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$userId = require_login();
csrf_verify_json_or_header_or_abort();

$raw = file_get_contents('php://input');
$data = json_decode(is_string($raw) ? $raw : '', true);
$tzRaw = is_array($data) && isset($data['timezone']) ? $data['timezone'] : '';
$tzStr = is_string($tzRaw) ? trim($tzRaw) : '';

$pdo = db();
if ($tzStr === '') {
    user_timezone_save($pdo, $userId, 'UTC');
} else {
    user_timezone_save($pdo, $userId, $tzStr);
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'timezone' => user_timezone_get($pdo, $userId)], JSON_UNESCAPED_UNICODE);
