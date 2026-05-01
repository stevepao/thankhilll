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

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
