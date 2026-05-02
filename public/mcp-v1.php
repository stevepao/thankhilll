<?php
/**
 * Minimal MCP JSON-RPC over HTTP — POST only, no SSE, no Accept checks.
 * Single file; no application includes (PDO + env inlined).
 */
declare(strict_types=1);

const MCP_JSON_RPC_VERSION = '2.0';

$projectRoot = dirname(__DIR__);

load_env_from_dotenv($projectRoot . '/.env');

if (!is_https_request()) {
    respond_json(403, ['error' => 'https_required']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond_plain(405, 'Method Not Allowed');
}

$authRaw = authorization_header_raw();
if ($authRaw === '' || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authRaw, $m)) {
    respond_unauthorized();
}

$pdo = pdo_from_env($projectRoot);
if (resolve_mcp_bearer_user_id($pdo, $m[1]) === null) {
    respond_unauthorized();
}
unset($pdo, $m, $authRaw);

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!preg_match('#^application/json\b#i', $contentType)) {
    respond_plain(415, 'Unsupported Media Type');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    respond_json_rpc(null, -32700, 'Parse error');
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond_json_rpc(null, -32700, 'Parse error');
}

if (!is_array($payload)) {
    respond_json_rpc(null, -32600, 'Invalid Request');
}

// JSON-RPC batch: not supported
if (array_is_list($payload)) {
    respond_json_rpc(null, -32600, 'Invalid Request');
}

$rpcVersion = $payload['jsonrpc'] ?? null;
if ($rpcVersion !== MCP_JSON_RPC_VERSION) {
    respond_json_rpc(null, -32600, 'Invalid Request');
}

$rpcMethod = $payload['method'] ?? null;
if (!is_string($rpcMethod) || $rpcMethod === '') {
    respond_json_rpc(null, -32600, 'Invalid Request');
}

$id = array_key_exists('id', $payload) ? $payload['id'] : null;
if (array_key_exists('id', $payload) && $id !== null && !is_string($id) && !is_int($id) && !is_float($id)) {
    respond_json_rpc(null, -32600, 'Invalid Request');
}
$isNotification = !array_key_exists('id', $payload);

if ($isNotification) {
    http_response_code(204);
    exit;
}

$params = $payload['params'] ?? null;
if ($params !== null && !is_array($params)) {
    respond_json_rpc($id, -32602, 'Invalid params');
}

switch ($rpcMethod) {
    case 'initialize':
        respond_json_rpc_success($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => new stdClass(),
            ],
            'serverInfo' => [
                'name' => 'thankhill',
                'version' => '1.0.0',
            ],
        ]);

    case 'tools/list':
        respond_json_rpc_success($id, ['tools' => []]);

    default:
        respond_json_rpc($id, -32601, 'Method not found');
}

/** @return never */
function respond_unauthorized(): void
{
    http_response_code(401);
    header('WWW-Authenticate: Bearer');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        [
            'jsonrpc' => MCP_JSON_RPC_VERSION,
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

/** @return never */
function respond_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/** @return never */
function respond_plain(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/**
 * @param string|int|float|null $id
 * @return never
 */
function respond_json_rpc_success(string|int|float|null $id, mixed $result): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        [
            'jsonrpc' => MCP_JSON_RPC_VERSION,
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
function respond_json_rpc(string|int|float|null $id, int $code, string $message): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        [
            'jsonrpc' => MCP_JSON_RPC_VERSION,
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

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $xf = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $xf === 'https';
}

function load_env_from_dotenv(string $path): void
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

function pdo_from_env(string $projectRoot): PDO
{
    $host = ($_ENV['DB_HOST'] ?? getenv('DB_HOST')) ?: 'localhost';
    $dbname = ($_ENV['DB_NAME'] ?? getenv('DB_NAME')) ?: '';
    $user = ($_ENV['DB_USER'] ?? getenv('DB_USER')) ?: '';
    $pass = ($_ENV['DB_PASS'] ?? getenv('DB_PASS')) ?: '';

    if ($dbname === '' || $user === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Database is not configured.';
        exit;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Database connection failed.';
        exit;
    }
}

function authorization_header_raw(): string
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

/**
 * Same rules as legacy mcp_access_tokens lookup (64-char hex -> SHA-256 hash).
 */
function resolve_mcp_bearer_user_id(PDO $pdo, string $plaintextHex): ?int
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
