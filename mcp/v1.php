<?php
/**
 * MCP v1 — minimal JSON-RPC (JSON-RPC 2.0) + Bearer token auth.
 *
 * Canonical URL path: /mcp/v1.php (avoid extensionless /mcp/v1 on shared hosting).
 */
declare(strict_types=1);

const TH_MCP_JSONRPC = '2.0';

/** Flags for JSON-RPC bodies: never drop output due to invalid UTF-8 in nested tool text. */
const TH_MCP_JSON_ENCODE = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

/** Same UTF-8 hardening without throw — safe for error_log and other non-RPC strings. */
const TH_MCP_JSON_ENCODE_LOG = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

$projectRoot = dirname(__DIR__);

th_mcp_load_env($projectRoot . '/.env');

$mcpUserId = th_mcp_authenticated_user_id($projectRoot);
if ($mcpUserId === null) {
    th_mcp_respond_401();
}

$httpMethod = $_SERVER['REQUEST_METHOD'] ?? '';

if ($httpMethod === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode(
        [
            'name' => 'thankhill-mcp',
            'version' => 'v1',
            'status' => 'ok',
        ],
        TH_MCP_JSON_ENCODE
    );
    exit;
}

if ($httpMethod !== 'POST') {
    header('Allow: GET, POST');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], TH_MCP_JSON_ENCODE);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!preg_match('#^application/json\b#i', $contentType)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(415);
    echo json_encode(['error' => 'unsupported_media_type'], TH_MCP_JSON_ENCODE);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    th_mcp_jsonrpc_error_response(null, -32700, 'Parse error');
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    th_mcp_jsonrpc_error_response(null, -32700, 'Parse error');
}

if (!is_array($payload) || array_is_list($payload)) {
    th_mcp_jsonrpc_error_response(null, -32600, 'Invalid Request');
}

$rpcVersion = $payload['jsonrpc'] ?? null;
if ($rpcVersion !== TH_MCP_JSONRPC) {
    th_mcp_jsonrpc_error_response(null, -32600, 'Invalid Request');
}

$rpcMethod = $payload['method'] ?? null;
if (!is_string($rpcMethod) || $rpcMethod === '') {
    th_mcp_jsonrpc_error_response(null, -32600, 'Invalid Request');
}

$idPresent = array_key_exists('id', $payload);
$id = $idPresent ? $payload['id'] : null;
if ($idPresent && $id !== null && !is_string($id) && !is_int($id) && !is_float($id)) {
    th_mcp_jsonrpc_error_response(null, -32600, 'Invalid Request');
}

if ($rpcMethod === 'notifications/initialized') {
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}

switch ($rpcMethod) {
    case 'initialize':
        th_mcp_jsonrpc_success($id, [
            'protocolVersion' => '2025-03-26',
            'serverInfo' => [
                'name' => 'thankhill-mcp',
                'version' => 'v1',
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => new stdClass(),
                'prompts' => new stdClass(),
            ],
        ]);

    case 'tools/list':
        th_mcp_jsonrpc_success($id, [
            'tools' => [
                th_mcp_tool_record_gratitude_definition(),
                th_mcp_tool_list_recent_photos_definition(),
                th_mcp_tool_export_notes_timeline_definition(),
            ],
        ]);

    case 'tools/call':
        $params = $payload['params'] ?? null;
        if (!is_array($params)) {
            th_mcp_jsonrpc_error_response($id, -32602, 'Invalid params');
        }
        $toolName = $params['name'] ?? null;
        $toolArgs = $params['arguments'] ?? [];
        if (!is_array($toolArgs)) {
            $toolArgs = [];
        }
        if (!is_string($toolName) || $toolName === '') {
            th_mcp_jsonrpc_error_response($id, -32602, 'Invalid params');
        }
        if ($toolName === 'record_gratitude') {
            require_once __DIR__ . '/record_gratitude.php';
            $pdo = db();
            $run = th_mcp_record_gratitude_run($pdo, $mcpUserId, $toolArgs);
            th_mcp_tool_result($id, $run['text'], $run['is_error']);
        }
        if ($toolName === 'list_recent_photos') {
            try {
                require_once __DIR__ . '/list_recent_photos.php';
                $pdo = db();
                $idLog = json_encode($id, TH_MCP_JSON_ENCODE_LOG);
                error_log(
                    'thankhill-mcp tools/call list_recent_photos: before run id=' . $idLog . ' tool=list_recent_photos'
                );
                $run = th_mcp_list_recent_photos_run($pdo, $mcpUserId, $toolArgs);
                error_log(
                    'thankhill-mcp tools/call list_recent_photos: after run id=' . $idLog . ' tool=list_recent_photos'
                );
                error_log(
                    'thankhill-mcp tools/call list_recent_photos: before th_mcp_tool_result id='
                    . $idLog
                    . ' tool=list_recent_photos'
                );
                th_mcp_tool_result($id, $run['text'], $run['is_error']);
            } catch (Throwable $e) {
                $idLog = json_encode($id, TH_MCP_JSON_ENCODE_LOG);
                error_log(
                    'thankhill-mcp tools/call list_recent_photos: exception id=' . $idLog
                    . ' tool=list_recent_photos msg=' . $e->getMessage()
                    . ' class=' . $e::class
                );
                error_log($e->getMessage());
                error_log($e->getTraceAsString());
                th_mcp_tool_result($id, '{"error":"list_recent_photos failed."}', true);
            }
        }
        if ($toolName === 'export_notes_timeline') {
            require_once __DIR__ . '/export_notes_timeline.php';
            $pdo = db();
            $run = th_mcp_export_notes_timeline_run($pdo, $mcpUserId, $toolArgs);
            th_mcp_tool_result($id, $run['text'], $run['is_error']);
        }
        th_mcp_tool_result($id, 'Unknown or unsupported tool.', true);

    default:
        th_mcp_jsonrpc_error_response($id, -32601, 'Method not implemented');
}

/** @return never */
function th_mcp_respond_401(): void
{
    http_response_code(401);
    header('WWW-Authenticate: Bearer');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(
        [
            'jsonrpc' => TH_MCP_JSONRPC,
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Unauthorized',
            ],
        ],
        TH_MCP_JSON_ENCODE
    );
    exit;
}

/**
 * @param string|int|float|null $id
 * @return never
 */
function th_mcp_jsonrpc_success(string|int|float|null $id, array $result): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode(
        [
            'jsonrpc' => TH_MCP_JSONRPC,
            'id' => $id,
            'result' => $result,
        ],
        TH_MCP_JSON_ENCODE
    );
    exit;
}

/**
 * @param string|int|float|null $id
 * @return never
 */
function th_mcp_jsonrpc_error_response(string|int|float|null $id, int $code, string $message): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode(
        [
            'jsonrpc' => TH_MCP_JSONRPC,
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ],
        TH_MCP_JSON_ENCODE
    );
    exit;
}

function th_mcp_authenticated_user_id(string $projectRoot): ?int
{
    $raw = th_mcp_authorization_header_raw();
    if ($raw === '' || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $raw, $m)) {
        return null;
    }

    try {
        $pdo = th_mcp_pdo_from_env($projectRoot);
    } catch (Throwable) {
        return null;
    }

    return th_mcp_resolve_bearer_user_id($pdo, $m[1]);
}

/**
 * @return never
 */
function th_mcp_tool_result(string|int|float|null $id, string $text, bool $isError): void
{
    th_mcp_jsonrpc_success($id, [
        'content' => [
            [
                'type' => 'text',
                'text' => $text,
            ],
        ],
        'isError' => $isError,
    ]);
}

/**
 * @return array{name:string,description:string,inputSchema:array<string,mixed>}
 */
function th_mcp_tool_export_notes_timeline_definition(): array
{
    return [
        'name' => 'export_notes_timeline',
        'description' => 'Export a timeline of notes activity for a date range visible to the authenticated user.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => [
                    'type' => 'string',
                    'description' => 'YYYY-MM-DD (inclusive)',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'YYYY-MM-DD (inclusive)',
                ],
                'include_view_urls' => [
                    'type' => 'boolean',
                    'description' => 'Include signed view URLs for photos (default true)',
                ],
            ],
            'required' => ['from', 'to'],
        ],
    ];
}

function th_mcp_tool_list_recent_photos_definition(): array
{
    return [
        'name' => 'list_recent_photos',
        'description' => 'List recent photos owned by the authenticated user, returning signed view URLs.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'How many photos to return (default 10)',
                ],
                'since' => [
                    'type' => 'string',
                    'description' => 'Optional ISO date/time; return photos created at/after this (optional)',
                ],
            ],
            'required' => [],
        ],
    ];
}

function th_mcp_tool_record_gratitude_definition(): array
{
    return [
        'name' => 'record_gratitude',
        'description' => 'Record gratitude text and/or attach existing photos to your daily note for a calendar day.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'Gratitude text to record (optional if photos provided)',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'YYYY-MM-DD (optional; defaults to today)',
                ],
                'photo_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional photo identifiers to attach',
                ],
            ],
            'required' => [],
        ],
    ];
}

function th_mcp_authorization_header_raw(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    $fromEnv = getenv('HTTP_AUTHORIZATION');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }
    $fromRedirectEnv = getenv('REDIRECT_HTTP_AUTHORIZATION');
    if (is_string($fromRedirectEnv) && $fromRedirectEnv !== '') {
        return $fromRedirectEnv;
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() ?: [] as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0 && is_string($v) && $v !== '') {
                return $v;
            }
        }
    }

    return '';
}

function th_mcp_load_env(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
            $q = $val[0];
            $val = trim(substr($val, 1), $q . ' ');
        }
        if ($key !== '') {
            $_ENV[$key] = $val;
        }
    }
    $loaded = true;
}

/**
 * @throws PDOException
 */
function th_mcp_pdo_from_env(string $projectRoot): PDO
{
    $host = ($_ENV['DB_HOST'] ?? getenv('DB_HOST')) ?: 'localhost';
    $dbname = ($_ENV['DB_NAME'] ?? getenv('DB_NAME')) ?: '';
    $user = ($_ENV['DB_USER'] ?? getenv('DB_USER')) ?: '';
    $pass = ($_ENV['DB_PASS'] ?? getenv('DB_PASS')) ?: '';

    if ($dbname === '' || $user === '') {
        throw new PDOException('Database not configured');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function th_mcp_resolve_bearer_user_id(PDO $pdo, string $plaintextHex): ?int
{
    $plaintextHex = strtolower(trim($plaintextHex));
    if (preg_match('/^[a-f0-9]{64}$/', $plaintextHex) !== 1) {
        return null;
    }
    $bin = hex2bin($plaintextHex);
    if ($bin === false || strlen($bin) !== 32) {
        return null;
    }
    $hashHex = hash('sha256', $bin);

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT user_id FROM mcp_access_tokens
            WHERE token_hash = ?
              AND revoked_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
            SQL
        );
        $stmt->execute([$hashHex]);
        $col = $stmt->fetchColumn();
    } catch (PDOException) {
        return null;
    }

    if ($col === false) {
        return null;
    }
    $uid = (int) $col;

    return $uid > 0 ? $uid : null;
}
