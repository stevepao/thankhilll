<?php
/**
 * includes/session.php — Central session bootstrap and hardened lifecycle for authenticated users.
 *
 * Provider-agnostic: any login flow should call session_commit_login() after verifying credentials.
 * Security intent: strict cookies, fixation resistance (regenerate on login), idle timeout, UA binding.
 */
declare(strict_types=1);

/** Idle timeout for authenticated sessions (seconds). */
const SESSION_IDLE_TIMEOUT_SECONDS = 30 * 60;

/**
 * Load .env so optional SESSION_COOKIE_SECURE is available before session_start().
 */
function session_load_env_if_needed(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    if (!function_exists('loadEnv')) {
        require_once dirname(__DIR__) . '/db.php';
    }
    loadEnv();
    $loaded = true;
}

/**
 * Detect HTTPS for Secure cookie flag (handles common reverse-proxy headers).
 */
function session_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && strtolower($forwarded) === 'https') {
        return true;
    }

    return false;
}

/**
 * Configure cookie flags, enable strict mode, start session, initialize anonymous metadata once.
 */
function bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_load_env_if_needed();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $secureEnv = $_ENV['SESSION_COOKIE_SECURE'] ?? getenv('SESSION_COOKIE_SECURE');
    $secure = ($secureEnv === '1' || $secureEnv === 'true' || $secureEnv === true)
        ? true
        : session_request_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!array_key_exists('_session_initialized_at', $_SESSION)) {
        $_SESSION['_session_initialized_at'] = time();
    }
}

/**
 * After successful authentication: new session id + bind user and activity metadata.
 */
function session_commit_login(int $userId): void
{
    bootstrap_session();

    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Refresh idle timestamp after validation (authenticated requests only).
 */
function session_touch_activity(): void
{
    $_SESSION['last_activity'] = time();
}

/**
 * True if bound session data matches current request (idle + User-Agent).
 */
function session_validate_authenticated(): bool
{
    $last = $_SESSION['last_activity'] ?? null;
    $boundAgent = $_SESSION['user_agent'] ?? null;
    $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($last === null || $boundAgent === null || !is_numeric($last)) {
        return false;
    }

    if ((time() - (int) $last) > SESSION_IDLE_TIMEOUT_SECONDS) {
        return false;
    }

    return hash_equals((string) $boundAgent, $currentAgent);
}

/**
 * Destroy session data, expire cookie, end session. Caller should redirect.
 */
function session_destroy_completely(): void
{
    bootstrap_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $name = session_name();
        setcookie($name, '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'] ?: '',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Log out and send user to the login page.
 */
function session_logout_and_redirect(): void
{
    session_destroy_completely();
    header('Location: /login.php');
    exit;
}
