<?php
/**
 * diag.php — Plain-text health check for production debugging.
 *
 * Usage:
 *   1. Deploy this file with your app (same folder as index.php).
 *   2. Visit https://your-domain/diag.php in the browser.
 *   3. Copy the entire output and share it (passwords are never printed; PDO errors may mention DB user/host).
 *
 * Remove or rename this file when you are finished troubleshooting.
 *
 * Output is buffered until after bootstrap_session() runs so Set-Cookie headers can still be sent;
 * otherwise prior echoes make session_start() fail with a misleading false negative.
 */
declare(strict_types=1);

ob_start();

header('Content-Type: text/plain; charset=UTF-8');

$root = __DIR__;

echo "=== Thankhill diagnostics ===\n\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
echo 'SAPI: ' . PHP_SAPI . "\n";
echo 'Document root (dirname of this script): ' . $root . "\n\n";

echo "--- Extensions ---\n";
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json', 'session'] as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? 'yes' : 'NO') . "\n";
}

echo "\n--- Paths ---\n";
$autoload = $root . '/vendor/autoload.php';
echo 'vendor/autoload.php: ' . (is_readable($autoload) ? 'readable' : 'MISSING') . "\n";
echo '.env: ' . (is_readable($root . '/.env') ? 'readable' : 'not found or not readable') . "\n";

if (!is_readable($autoload)) {
    echo "\nFix: SSH into hosting, cd to this folder, run:\n  composer install --no-dev --optimize-autoloader\n";
    exit;
}

require_once $root . '/db.php';

try {
    loadEnv();
} catch (Throwable $e) {
    echo "\n.env loading failed: " . $e->getMessage() . "\n";
    exit;
}

echo "\n--- Environment (values hidden) ---\n";
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    $v = $_ENV[$key] ?? '';
    $status = $v === '' ? 'empty or unset' : 'set (' . strlen((string) $v) . ' chars)';
    echo $key . ': ' . $status . "\n";
}

echo "\n--- Session (login pages need this) ---\n";
$savePathIni = ini_get('session.save_path') ?: '';
echo 'session.save_path (ini): ' . ($savePathIni !== '' ? $savePathIni : '(empty — PHP default temp)') . "\n";
$effective = session_save_path();
if ($effective === '') {
    $effective = sys_get_temp_dir();
}
echo 'effective path checked for writable: ' . $effective . "\n";
echo 'writable: ' . (is_writable($effective) ? 'yes' : 'NO — can cause HTTP 500 on login') . "\n";

require_once $root . '/includes/session.php';
try {
    bootstrap_session();
    echo "bootstrap_session(): OK\n";
} catch (Throwable $e) {
    echo 'bootstrap_session(): FAILED — ' . $e->getMessage() . "\n";
}

echo "\n--- Database ping ---\n";
echo "(login.php does not open a DB connection for guests; Today/index.php does — failures here match that 500.)\n";
try {
    db()->query('SELECT 1');
    echo "PDO: OK — connected and SELECT 1 succeeded.\n";
} catch (Throwable $e) {
    echo 'PDO: FAILED — ' . $e->getMessage() . "\n";
}

echo "\nDone. Delete diag.php when finished troubleshooting.\n";
