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
$tokenHashRaw = is_array($decoded) ? ($decoded['token_hash'] ?? null) : null;

$hasId = is_numeric($tokenIdRaw) && (int) $tokenIdRaw > 0;
$tokenHashNorm = is_string($tokenHashRaw) ? strtolower(trim($tokenHashRaw)) : '';
$hasHash = $tokenHashNorm !== '' && preg_match('/^[a-f0-9]{64}$/', $tokenHashNorm) === 1;

if ($hasId && $hasHash) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'ambiguous_identifier'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$hasId && !$hasHash) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_token_identifier'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($hasId) {
        $result = mcp_access_token_revoke(db(), $userId, (int) $tokenIdRaw);
    } else {
        $result = mcp_access_token_revoke_by_hash(db(), $userId, $tokenHashNorm);
    }
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
