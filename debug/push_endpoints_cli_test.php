#!/usr/bin/env php
<?php
/**
 * push_endpoints_cli_test.php — CLI integration test for POST /push/subscribe and /push/unsubscribe.
 *
 * Requires HTTP reachable at THANKHILL_BASE_URL where **web** PHP uses the **same**
 * session.save_path as this CLI (THANKHILL_SESSION_SAVE_PATH). Otherwise Cookie PHPSESSID
 * points at files the web worker never reads → 401 unauthenticated.
 * Does not send push notifications.
 *
 * Usage:
 *   export THANKHILL_BASE_URL=http://127.0.0.1:8080
 *   export THANKHILL_SESSION_SAVE_PATH=/tmp/thankhill_push_test_sess
 *   mkdir -p "$THANKHILL_SESSION_SAVE_PATH"
 *   php -d session.save_path="$THANKHILL_SESSION_SAVE_PATH" -S 127.0.0.1:8080 -t /path/to/thankhill
 *   php debug/push_endpoints_cli_test.php
 *
 * Or pass base URL as argv[1]:
 *   php debug/push_endpoints_cli_test.php http://127.0.0.1:8080
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

// Buffer stdout until after session bootstrap; otherwise echo before session_start() breaks
// bootstrap_session() with "Headers already sent" (see debug/diag.php).
ob_start();

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
require_once $root . '/db.php';
require_once $root . '/auth.php';
require_once $root . '/includes/csrf.php';
require_once $root . '/includes/user_notification_prefs_repository.php';

loadEnv();

const PUSH_TEST_UA = 'ThankhillPushEndpointCLI/1.0';

/**
 * @return array{0: int, 1: string}
 */
function push_test_http_post_json(string $url, string $jsonBody, string $cookieHeader): array
{
    if (!function_exists('curl_init')) {
        return [0, 'curl extension required'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [0, ''];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Cookie: ' . $cookieHeader,
            'User-Agent: ' . PUSH_TEST_UA,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, is_string($body) ? $body : ''];
}

function push_test_count_subs(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

/** @param array<string, mixed> $payload */
function push_test_post_endpoint(string $baseUrl, string $path, array $payload, string $cookieHeader): array
{
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return [0, ''];
    }

    return push_test_http_post_json($url, $json, $cookieHeader);
}

// --- Align session storage with the HTTP server (must match php -d session.save_path=...)
$sessEnv = getenv('THANKHILL_SESSION_SAVE_PATH');
if (is_string($sessEnv) && $sessEnv !== '') {
    ini_set('session.save_path', $sessEnv);
}

/**
 * @throws RuntimeException if session files cannot be stored (common cause of exit 255).
 */
function push_test_ensure_session_save_path_writable(): void
{
    $path = session_save_path();
    if ($path === '') {
        return;
    }
    if (!is_dir($path)) {
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(
                'Cannot create session.save_path directory: ' . $path
            );
        }
    }
    if (!is_writable($path)) {
        throw new RuntimeException(
            'session.save_path is not writable (chmod or choose another directory): ' . $path
        );
    }
}

$_SERVER['HTTP_USER_AGENT'] = PUSH_TEST_UA;

$baseUrl = getenv('THANKHILL_BASE_URL');
if (!is_string($baseUrl) || trim($baseUrl) === '') {
    $cliArgv = $_SERVER['argv'] ?? [];
    $baseUrl = isset($cliArgv[1]) && is_string($cliArgv[1]) ? trim($cliArgv[1]) : '';
}
if ($baseUrl === '') {
    $baseUrl = 'http://127.0.0.1:8080';
}

echo "=== Push endpoints CLI test ===\n";
echo "Base URL: {$baseUrl}\n";
echo 'session.save_path (CLI): ' . (session_save_path() ?: '(default temp)') . "\n";
echo "User-Agent (session + HTTP): " . PUSH_TEST_UA . "\n";

if (!function_exists('curl_init')) {
    fwrite(STDERR, "FAIL: PHP curl extension is required.\n");
    exit(1);
}

echo "\n";

$pdo = db();

$userId = null;
try {
    $ins = $pdo->prepare(
        'INSERT INTO users (display_name, preferences_json) VALUES (?, NULL)'
    );
    $ins->execute(['push_endpoints_cli_test']);
    $userId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: could not create test user: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($userId <= 0) {
    fwrite(STDERR, "FAIL: invalid user id\n");
    exit(1);
}

$exitCode = 1;
try {

push_test_ensure_session_save_path_writable();
bootstrap_session();
session_commit_login($userId);
$csrf = csrf_token();
$sessionName = session_name();
$sessionIdValue = session_id();
$cookieHeader = $sessionName . '=' . $sessionIdValue;
$savePathForFile = session_save_path();
session_write_close();

$sessDataFile = $savePathForFile !== ''
    ? rtrim($savePathForFile, '/') . '/sess_' . $sessionIdValue
    : '(session.save_path empty in CLI — using PHP default temp dir)';
$sessOk = $savePathForFile !== '' && is_file(
    rtrim($savePathForFile, '/') . '/sess_' . $sessionIdValue
);

echo "[setup] Test user_id={$userId}, CSRF obtained, session cookie prepared.\n";
echo '[diag] CLI wrote session data file: ' . $sessDataFile . "\n";
echo '[diag] That file exists on disk: ' . ($sessOk ? 'yes' : 'no') . "\n";
echo "[diag] Web PHP must use this same session.save_path or requests stay logged out (401).\n\n";

$endpoint = 'https://fcm.googleapis.com/fcm/send/cli-test-' . bin2hex(random_bytes(16));
$p256dh = 'BOpush_test_p256dh_' . rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '=');
$auth = 'auth_push_' . rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
$p256dh2 = 'BOpush_test_p256dh2_' . rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '=');
$auth2 = 'auth_push2_' . rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

$subsPayload = [
    'endpoint' => $endpoint,
    'keys' => [
        'p256dh' => $p256dh,
        'auth' => $auth,
    ],
    'expirationTime' => null,
    'userAgent' => 'cli-test',
    'csrf_token' => $csrf,
];

$n = push_test_count_subs($pdo, $userId);
echo "[count] push_subscriptions for user (initial): {$n}\n";

[$code1, $body1] = push_test_post_endpoint($baseUrl, 'push/subscribe.php', $subsPayload, $cookieHeader);
$n = push_test_count_subs($pdo, $userId);
$ok1 = $code1 === 200 && str_contains($body1, '"ok":true');
echo '[step 1] POST push/subscribe.php (first) — HTTP ' . $code1 . ($ok1 ? ' PASS' : ' FAIL') . "\n";
echo "        Response: " . substr($body1, 0, 200) . (strlen($body1) > 200 ? '...' : '') . "\n";
echo "[count] push_subscriptions for user: {$n}\n\n";

if (!$ok1 || $n !== 1) {
    if ($code1 === 401) {
        echo "HINT: 401 unauthenticated — Apache/php-fpm did not load the CLI session.\n";
        echo "      curl sends Cookie: {$cookieHeader}\n";
        echo "      The web worker must read the same sess_* file. Configure **web** PHP to use\n";
        echo "      the identical session.save_path as CLI, e.g. site-root .user.ini:\n";
        echo "        session.save_path = \"" . ($savePathForFile !== '' ? $savePathForFile : '/absolute/path/to/shared/sessions') . "\"\n";
        echo "      Ensure that directory is readable/writable by **both** your SSH user and the\n";
        echo "      web server user (www-data, apache, …). chmod/chgrp as needed.\n";
        echo "      Local dev only: php -d session.save_path=… -S 127.0.0.1:8080 -t {$root}\n";
    }
}

$subsPayloadDup = [
    'endpoint' => $endpoint,
    'keys' => [
        'p256dh' => $p256dh2,
        'auth' => $auth2,
    ],
    'csrf_token' => $csrf,
];

[$code2, $body2] = push_test_post_endpoint($baseUrl, 'push/subscribe.php', $subsPayloadDup, $cookieHeader);
$n2 = push_test_count_subs($pdo, $userId);
$ok2 = $code2 === 200 && str_contains($body2, '"ok":true') && $n2 === 1;
echo '[step 2] POST push/subscribe.php (same endpoint, new keys) — HTTP ' . $code2 . ($ok2 ? ' PASS' : ' FAIL') . "\n";
echo "        (expect count still 1, no duplicate row)\n";
echo "[count] push_subscriptions for user: {$n2}\n\n";

$unsubPayload = [
    'endpoint' => $endpoint,
    'csrf_token' => $csrf,
];

[$code3, $body3] = push_test_post_endpoint($baseUrl, 'push/unsubscribe.php', $unsubPayload, $cookieHeader);
$n3 = push_test_count_subs($pdo, $userId);
$ok3 = $code3 === 200 && str_contains($body3, '"ok":true') && $n3 === 0;
echo '[step 3] POST push/unsubscribe.php — HTTP ' . $code3 . ($ok3 ? ' PASS' : ' FAIL') . "\n";
echo "[count] push_subscriptions for user: {$n3}\n\n";

[$code4, $body4] = push_test_post_endpoint($baseUrl, 'push/unsubscribe.php', $unsubPayload, $cookieHeader);
$n4 = push_test_count_subs($pdo, $userId);
$ok4 = $code4 === 200 && str_contains($body4, '"ok":true') && $n4 === 0;
echo '[step 4] POST push/unsubscribe.php again (idempotent) — HTTP ' . $code4 . ($ok4 ? ' PASS' : ' FAIL') . "\n";
echo "[count] push_subscriptions for user: {$n4}\n\n";

[$code5, $body5] = push_test_post_endpoint($baseUrl, 'push/subscribe.php', $subsPayload, $cookieHeader);
$n5 = push_test_count_subs($pdo, $userId);
$ok5 = $code5 === 200 && str_contains($body5, '"ok":true') && $n5 === 1;
echo '[step 5] POST subscribe (re-create subscription) — HTTP ' . $code5 . ($ok5 ? ' PASS' : ' FAIL') . "\n";
echo "[count] push_subscriptions for user: {$n5}\n\n";

user_notification_prefs_save($pdo, $userId, false, false, false);
$n6 = push_test_count_subs($pdo, $userId);
$ok6 = $n6 === 0;
echo "[step 6] user_notification_prefs_save(all false) — opt-out clears subs " . ($ok6 ? 'PASS' : 'FAIL') . "\n";
echo "[count] push_subscriptions for user: {$n6}\n\n";

$globalCount = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
echo "Final push_subscriptions row count (full table): {$globalCount}\n";

$exitCode = ($ok1 && $ok2 && $ok3 && $ok4 && $ok5 && $ok6) ? 0 : 1;
if ($exitCode !== 0) {
    echo "\nFAIL: One or more steps failed.\n";
}
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL (exception): ' . $e->getMessage() . "\n");
    fwrite(STDERR, '  in ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $exitCode = 1;
} finally {
    if (isset($userId) && $userId > 0) {
        try {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            echo "[cleanup] Deleted test user_id={$userId}\n";
        } catch (Throwable $e) {
            fwrite(STDERR, '[cleanup] FAILED: ' . $e->getMessage() . "\n");
        }
    }
}

exit($exitCode);
