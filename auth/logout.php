<?php
/**
 * Logs out current session and redirects to login.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

ensureSessionStarted();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

header('Location: /login.php');
exit;
