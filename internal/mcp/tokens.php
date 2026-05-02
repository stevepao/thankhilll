<?php
/**
 * GET /internal/mcp/tokens — JSON list of MCP tokens for the signed-in user (no CSRF on GET).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/mcp_access_token.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
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

try {
    $tokens = mcp_access_tokens_list_for_user(db(), $userId);
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
echo json_encode(['ok' => true, 'tokens' => $tokens], JSON_UNESCAPED_UNICODE);
