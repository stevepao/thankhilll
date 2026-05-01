<?php
/**
 * auth.php — Shared session/auth helpers (provider-agnostic).
 *
 * Session wiring lives in includes/session.php; this file exposes user id + DB-backed profile helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/session.php';

/**
 * Parse logged-in user id from session, enforce idle timeout + User-Agent binding, refresh activity.
 * Returns null when unauthenticated or when the session fails validation (session is cleared).
 */
function current_user_id(): ?int
{
    bootstrap_session();

    $value = $_SESSION['user_id'] ?? null;

    $id = null;
    if (is_int($value) && $value > 0) {
        $id = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $id = (int) $value;
    }

    if ($id === null) {
        return null;
    }

    if (!session_validate_authenticated()) {
        session_destroy_completely();
        return null;
    }

    session_touch_activity();

    return $id;
}

/**
 * Require an authenticated user or redirect to login.
 */
function require_login(): int
{
    $userId = current_user_id();
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
    $userId = current_user_id();
    if ($userId === null) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT id, display_name, timezone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_column($e)) {
            throw $e;
        }
        $stmt = db()->prepare('SELECT id, display_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['timezone'] = null;

        return $row;
    }

    return is_array($row) ? $row : null;
}

/** Compatibility alias; prefer bootstrap_session() in new code. */
function ensureSessionStarted(): void
{
    bootstrap_session();
}

function currentUserId(): ?int
{
    return current_user_id();
}

function requireLogin(): int
{
    return require_login();
}
