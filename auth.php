<?php
/**
 * auth.php — Shared session/auth helpers.
 *
 * Keeps authentication state minimal: only user_id is stored in session.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Start PHP session once per request.
 */
function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Returns the logged-in user id, or null.
 */
function currentUserId(): ?int
{
    ensureSessionStarted();
    $value = $_SESSION['user_id'] ?? null;

    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }

    return null;
}

/**
 * Redirects to login page when no session user exists.
 */
function requireLogin(): int
{
    $userId = currentUserId();
    if ($userId === null) {
        header('Location: /login.php');
        exit;
    }

    return $userId;
}

/**
 * Returns the current user row for display purposes.
 */
function currentUser(): ?array
{
    $userId = currentUserId();
    if ($userId === null) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, display_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}
