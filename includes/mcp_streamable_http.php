<?php
/**
 * MCP Streamable HTTP transport — POST JSON-RPC + minimal GET SSE at /mcp/v1.
 *
 * Transport is intentionally permissive (HTTP/HTTPS, optional Origin/Accept) so the
 * endpoint works behind typical shared hosts and CLI clients. Authentication is still
 * required via Bearer MCP token on every request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/mcp_access_token.php';

/** When set in $GLOBALS, current_user_id() returns this without starting a browser session. */
const THANKHILL_MCP_HTTP_USER_GLOBAL_KEY = 'THANKHILL_MCP_HTTP_USER_ID';

const MCP_HTTP_MAX_BODY_BYTES = 262144;

/** Protocol versions this server speaks for initialize negotiation. */
const THANKHILL_MCP_SUPPORTED_PROTOCOL_VERSIONS = ['2025-03-26', '2024-11-05'];

function mcp_http_send_response_headers(): void
{
    header('Cache-Control: no-store');
}

/**
 * @param-out array{trace_id: string, t0: float, http_method: string, user_id: ?int, http_status: int} $ctx
 */
function mcp_http_access_log_register(array &$ctx): void
{
    register_shutdown_function(function () use (&$ctx): void {
        $code = $ctx['http_status'] ?? 0;
        if ($code <= 0) {
            $code = http_response_code() ?: 500;
        }
        error_log(json_encode([
            'channel' => 'mcp_http_access',
            'request_id' => $ctx['trace_id'],
            'method' => $ctx['http_method'],
            'user_id' => $ctx['user_id'],
            'http_status' => $code,
            'duration_ms' => round((microtime(true) - ($ctx['t0'] ?? microtime(true))) * 1000, 3),
        ], JSON_UNESCAPED_UNICODE));
    });
}

/** Lenient: empty Accept passes; otherwise require substring (MCP clients vary behind proxies). */
function mcp_http_accept_ok_for_post(string $accept): bool
{
    $accept = trim($accept);

    return $accept === '' || stripos($accept, 'application/json') !== false;
}

function mcp_http_accept_ok_for_get(string $accept): bool
{
    $accept = trim($accept);

    return $accept === '' || stripos($accept, 'text/event-stream') !== false;
}

function mcp_http_content_type_json(string $contentType): bool
{
    return preg_match('#^application/json\b#i', $contentType) === 1;
}

function mcp_http_parse_bearer(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($h)) {
        return null;
    }
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $h, $m) !== 1) {
        return null;
    }

    return $m[1];
}

function mcp_http_content_length_too_large(): bool
{
    $cl = $_SERVER['CONTENT_LENGTH'] ?? '';
    if (!is_string($cl) || !ctype_digit($cl)) {
        return false;
    }

    return (int) $cl > MCP_HTTP_MAX_BODY_BYTES;
}

/**
 * @return array<string, mixed>|list<mixed>|null
 */
function mcp_http_json_decode_body(string $raw): ?array
{
    if ($raw === '') {
        return null;
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

function mcp_http_is_batch_array(mixed $decoded): bool
{
    if (!is_array($decoded)) {
        return false;
    }
    if ($decoded === []) {
        return true;
    }

    return array_keys($decoded) === range(0, count($decoded) - 1);
}

function mcp_http_is_notification(array $msg): bool
{
    return isset($msg['method']) && is_string($msg['method']) && !array_key_exists('id', $msg);
}

function mcp_http_is_jsonrpc_request(array $msg): bool
{
    return isset($msg['method']) && is_string($msg['method']) && array_key_exists('id', $msg);
}

function mcp_http_is_jsonrpc_response(array $msg): bool
{
    return !isset($msg['method'])
        && array_key_exists('id', $msg)
        && (array_key_exists('result', $msg) || array_key_exists('error', $msg));
}

function mcp_http_batch_has_jsonrpc_request(array $batch): bool
{
    foreach ($batch as $item) {
        if (is_array($item) && mcp_http_is_jsonrpc_request($item)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>|null Single JSON-RPC response object
 */
function mcp_http_dispatch_request(array $msg, int $userId): ?array
{
    $method = isset($msg['method']) && is_string($msg['method']) ? $msg['method'] : '';
    $id = $msg['id'] ?? null;

    if ($method === '') {
        return mcp_http_jsonrpc_error($id, -32600, 'Invalid Request');
    }

    if ($method === 'initialize') {
        $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
        $reqPv = $params['protocolVersion'] ?? null;
        $protocolVersion = '2025-03-26';
        if (is_string($reqPv) && in_array($reqPv, THANKHILL_MCP_SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $protocolVersion = $reqPv;
        }

        $result = [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => new stdClass(),
                'prompts' => new stdClass(),
            ],
            'serverInfo' => [
                'name' => 'Thankhill MCP',
                'version' => 'v1',
            ],
        ];

        return mcp_http_jsonrpc_result($id, $result);
    }

    if ($method === 'tools/list') {
        return mcp_http_jsonrpc_result($id, ['tools' => []]);
    }

    return mcp_http_jsonrpc_error($id, -32601, 'Method not implemented');
}

function mcp_http_jsonrpc_result(mixed $id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function mcp_http_jsonrpc_error(mixed $id, int $code, string $message, mixed $data = null): array
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
}

function mcp_http_handle_notification(array $msg, int $userId): void
{
}

function mcp_http_batch_has_initialize(mixed $decoded): bool
{
    if (!is_array($decoded) || !mcp_http_is_batch_array($decoded)) {
        return false;
    }
    foreach ($decoded as $item) {
        if (is_array($item) && ($item['method'] ?? '') === 'initialize') {
            return true;
        }
    }

    return false;
}

/**
 * @param list<mixed> $batch
 * @return list<array<string, mixed>>
 */
function mcp_http_dispatch_batch(array $batch, int $userId): array
{
    $out = [];
    foreach ($batch as $item) {
        if (!is_array($item)) {
            $out[] = mcp_http_jsonrpc_error(null, -32600, 'Invalid Request');

            continue;
        }
        if (mcp_http_is_notification($item)) {
            mcp_http_handle_notification($item, $userId);

            continue;
        }
        if (mcp_http_is_jsonrpc_response($item)) {
            continue;
        }
        if (mcp_http_is_jsonrpc_request($item)) {
            $out[] = mcp_http_dispatch_request($item, $userId);

            continue;
        }

        $out[] = mcp_http_jsonrpc_error(null, -32600, 'Invalid Request');
    }

    return $out;
}

function mcp_http_emit_json(array &$ctx, int $httpStatus, array $body): void
{
    $ctx['http_status'] = $httpStatus;
    http_response_code($httpStatus);
    mcp_http_send_response_headers();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function mcp_http_emit_no_content(array &$ctx): void
{
    $ctx['http_status'] = 204;
    http_response_code(204);
    mcp_http_send_response_headers();
}

function mcp_http_emit_auth_failure(array &$ctx): void
{
    $ctx['http_status'] = 401;
    http_response_code(401);
    mcp_http_send_response_headers();
    header('Content-Type: application/json; charset=UTF-8');
    header('WWW-Authenticate: Bearer realm="Thankhill MCP"');
    echo json_encode(
        mcp_http_jsonrpc_error(null, -32001, 'Unauthorized'),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function mcp_http_emit_get_sse_stub(array &$ctx): void
{
    $ctx['http_status'] = 200;
    http_response_code(200);
    mcp_http_send_response_headers();
    header('Content-Type: text/event-stream; charset=utf-8');
    echo ": ok\n\n";
}

function mcp_v1_main(): void
{
    $traceId = bin2hex(random_bytes(8));
    $GLOBALS[THANKHILL_MCP_HTTP_USER_GLOBAL_KEY] = null;

    $ctx = [
        'trace_id' => $traceId,
        't0' => microtime(true),
        'http_method' => is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '',
        'user_id' => null,
        'http_status' => 0,
    ];
    mcp_http_access_log_register($ctx);

    $httpMethod = $ctx['http_method'];
    $accept = isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT'])
        ? $_SERVER['HTTP_ACCEPT']
        : '';

    $token = mcp_http_parse_bearer();
    if ($token === null || $token === '') {
        mcp_http_emit_auth_failure($ctx);

        return;
    }

    try {
        $pdo = db();
        $userId = mcp_access_token_resolve_user_id($pdo, $token);
    } catch (Throwable $e) {
        error_log('mcp_http resolve token (internal error)');
        $userId = null;
    }

    if ($userId === null || $userId <= 0) {
        mcp_http_emit_auth_failure($ctx);

        return;
    }

    $ctx['user_id'] = $userId;
    $GLOBALS[THANKHILL_MCP_HTTP_USER_GLOBAL_KEY] = $userId;

    if ($httpMethod === 'GET') {
        if (!mcp_http_accept_ok_for_get($accept)) {
            mcp_http_emit_json($ctx, 406, mcp_http_jsonrpc_error(null, -32004, 'Not Acceptable'));

            return;
        }
        mcp_http_emit_get_sse_stub($ctx);

        return;
    }

    if ($httpMethod !== 'POST') {
        $ctx['http_status'] = 405;
        http_response_code(405);
        mcp_http_send_response_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Allow: POST, GET');
        echo json_encode(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32006, 'message' => 'Method not allowed']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return;
    }

    if (!mcp_http_accept_ok_for_post($accept)) {
        mcp_http_emit_json($ctx, 406, mcp_http_jsonrpc_error(null, -32004, 'Not Acceptable'));

        return;
    }

    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $contentType = is_string($ct) ? $ct : '';
    if (!mcp_http_content_type_json($contentType)) {
        mcp_http_emit_json($ctx, 415, mcp_http_jsonrpc_error(null, -32005, 'Unsupported Media Type'));

        return;
    }

    if (mcp_http_content_length_too_large()) {
        mcp_http_emit_json($ctx, 413, mcp_http_jsonrpc_error(null, -32007, 'Payload too large'));

        return;
    }

    $raw = file_get_contents('php://input', false, null, 0, MCP_HTTP_MAX_BODY_BYTES + 1);
    $rawStr = is_string($raw) ? $raw : '';
    if (strlen($rawStr) > MCP_HTTP_MAX_BODY_BYTES) {
        mcp_http_emit_json($ctx, 413, mcp_http_jsonrpc_error(null, -32007, 'Payload too large'));

        return;
    }

    $decoded = mcp_http_json_decode_body($rawStr);
    if ($decoded === null) {
        mcp_http_emit_json($ctx, 400, mcp_http_jsonrpc_error(null, -32700, 'Parse error'));

        return;
    }

    if (mcp_http_is_batch_array($decoded)) {
        /** @var list<mixed> $decoded */
        if ($decoded === []) {
            mcp_http_emit_no_content($ctx);

            return;
        }

        if (mcp_http_batch_has_initialize($decoded)) {
            mcp_http_emit_json($ctx, 400, mcp_http_jsonrpc_error(null, -32600, 'Initialize must not be batched'));

            return;
        }

        if (!mcp_http_batch_has_jsonrpc_request($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item) && mcp_http_is_notification($item)) {
                    mcp_http_handle_notification($item, $userId);
                }
            }
            mcp_http_emit_no_content($ctx);

            return;
        }

        $responses = mcp_http_dispatch_batch($decoded, $userId);
        $ctx['http_status'] = 200;
        http_response_code(200);
        mcp_http_send_response_headers();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return;
    }

    /** @var array<string, mixed> $decoded */
    if (mcp_http_is_jsonrpc_response($decoded)) {
        mcp_http_emit_no_content($ctx);

        return;
    }

    if (mcp_http_is_notification($decoded)) {
        mcp_http_handle_notification($decoded, $userId);
        mcp_http_emit_no_content($ctx);

        return;
    }

    $resp = mcp_http_dispatch_request($decoded, $userId);
    if ($resp === null) {
        mcp_http_emit_no_content($ctx);

        return;
    }

    mcp_http_emit_json($ctx, 200, $resp);
}
