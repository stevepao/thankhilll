<?php
/**
 * POST /internal/mcp/token/create — issue MCP bearer token for current session user.
 * Not linked from UI; JSON only. Requires session auth + CSRF (body.csrf_token or X-CSRF-Token).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/csrf.php';
require_once dirname(__DIR__, 3) . '/includes/mcp_access_token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = current_user_id();
if ($userId === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'unauthenticated'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode(is_string($rawBody) ? $rawBody : '', true);
csrf_verify_decoded_json_or_header_or_abort(is_array($decoded) ? $decoded : null);

$label = null;
if (is_array($decoded) && array_key_exists('label', $decoded)) {
    $label = is_string($decoded['label']) ? $decoded['label'] : null;
}

try {
    $pdo = db();
    $issued = mcp_access_token_issue($pdo, $userId, $label);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'mcp_access_tokens table missing')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'migration_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw $e;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(
    [
        'ok' => true,
        'token' => $issued['token'],
        'expires_at' => $issued['expires_at'],
        'warning' => 'Store this token securely. It cannot be shown again.',
    ],
    JSON_UNESCAPED_UNICODE
);
