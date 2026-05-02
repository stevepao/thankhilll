<?php
/**
 * POST /internal/mcp/token/revoke — revoke one MCP token owned by the current user (CSRF required).
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

$tokenIdRaw = is_array($decoded) ? ($decoded['token_id'] ?? null) : null;
if (!is_numeric($tokenIdRaw)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_token_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenId = (int) $tokenIdRaw;
if ($tokenId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_token_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = mcp_access_token_revoke(db(), $userId, $tokenId);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'mcp_access_tokens table missing')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'migration_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw $e;
}

if (!$result['ok']) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = ['ok' => true];
if (!empty($result['already_revoked'])) {
    $payload['already_revoked'] = true;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
