<?php
/**
 * MCP v1 — minimal JSON-RPC (JSON-RPC 2.0) + Bearer token auth.
 *
 * Canonical URL path: /mcp/v1.php (avoid extensionless /mcp/v1 on shared hosting).
 */
declare(strict_types=1);

const TH_MCP_JSONRPC = '2.0';

$projectRoot = dirname(__DIR__);

th_mcp_load_env($projectRoot . '/.env');

if (!th_mcp_authorization_ok($projectRoot)) {
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
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

if ($httpMethod !== 'POST') {
    header('Allow: GET, POST');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!preg_match('#^application/json\b#i', $contentType)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code(415);
    echo json_encode(['error' => 'unsupported_media_type'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
        th_mcp_jsonrpc_success($id, ['tools' => []]);

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
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
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
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
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
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

function th_mcp_authorization_ok(string $projectRoot): bool
{
    $raw = th_mcp_authorization_header_raw();
    if ($raw === '' || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $raw, $m)) {
        return false;
    }

    try {
        $pdo = th_mcp_pdo_from_env($projectRoot);
    } catch (Throwable) {
        return false;
    }

    return th_mcp_resolve_bearer_user_id($pdo, $m[1]) !== null;
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
