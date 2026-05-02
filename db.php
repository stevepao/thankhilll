<?php
/**
 * db.php — Shared PDO connection to MySQL (IONOS or any MySQL host).
 *
 * Loads environment values from a root .env file using vlucas/phpdotenv,
 * then builds a shared PDO connection. Uses UTF-8 and throws exceptions on errors.
 */

declare(strict_types=1);

$thankhillAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($thankhillAutoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Application dependencies are missing. From the project root on this server, run:\n\n  composer install --no-dev --optimize-autoloader\n\nThen reload the page.\n";
    exit;
}

require_once $thankhillAutoload;

use Dotenv\Dotenv;

/**
 * Loads .env values into $_ENV once per request.
 */
function loadEnv(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    // Dotenv reads key/value pairs from .env into PHP's environment arrays.
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();

    $loaded = true;
}

/**
 * Read a string env value after loadEnv(). Uses getenv() if the key is missing from $_ENV — some
 * PHP builds use variables_order without "E", leaving $_ENV empty while Dotenv still sets getenv().
 */
function env_var(string $key, string $default = ''): string
{
    loadEnv();

    if (array_key_exists($key, $_ENV) && is_string($_ENV[$key])) {
        return trim($_ENV[$key]);
    }

    $g = getenv($key);
    if ($g !== false) {
        return trim((string) $g);
    }

    return $default;
}

/**
 * Returns a singleton PDO instance for the whole request.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadEnv();

    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $dbname = $_ENV['DB_NAME'] ?? 'your_database_name';
    $user = $_ENV['DB_USER'] ?? 'your_mysql_user';
    $pass = $_ENV['DB_PASS'] ?? 'your_mysql_password';

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'could not find driver') !== false) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "PDO MySQL driver is missing (pdo_mysql). Enable the pdo_mysql extension for this PHP version in your hosting panel, then reload.\n";
            exit;
        }
        throw $e;
    }

    return $pdo;
}

/** True when MySQL reports an unknown column (e.g. migration not applied yet). */
function pdo_error_is_unknown_column(PDOException $e): bool
{
    return ($e->errorInfo[0] ?? '') === '42S22'
        || (int) ($e->errorInfo[1] ?? 0) === 1054;
}
