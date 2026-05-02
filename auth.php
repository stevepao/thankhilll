<?php
/**
 * auth.php — Shared session/auth helpers (provider-agnostic).
 *
 * Session wiring lives in includes/session.php; bounded refresh cookies live in includes/auth_refresh_token.php.
 * Idle timeout remains server-side (session.php); expired idle sessions may be restored via HttpOnly refresh
 * cookie until the refresh token’s absolute expiry (see AUTH_REFRESH_TOKEN_LIFETIME_SECONDS).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/auth_refresh_token.php';

/**
 * Parse logged-in user id from session, enforce idle timeout + User-Agent binding, refresh activity.
 * If the PHP session is absent or idle-expired, tries HttpOnly refresh cookie + DB token once before clearing.
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

    if ($id !== null) {
        if (session_validate_authenticated()) {
            session_touch_activity();

            return $id;
        }

        try {
            $pdo = db();
            if (auth_refresh_token_try_restore_session($pdo)) {
                $v2 = $_SESSION['user_id'] ?? null;
                if (is_int($v2) && $v2 > 0) {
                    return $v2;
                }
                if (is_string($v2) && ctype_digit($v2)) {
                    return (int) $v2;
                }
            }
        } catch (Throwable $e) {
            error_log('current_user_id refresh: ' . $e->getMessage());
        }
        session_destroy_completely();

        return null;
    }

    try {
        $pdo = db();
        if (auth_refresh_token_try_restore_session($pdo)) {
            $v2 = $_SESSION['user_id'] ?? null;
            if (is_int($v2) && $v2 > 0) {
                return $v2;
            }
            if (is_string($v2) && ctype_digit($v2)) {
                return (int) $v2;
            }
        }
    } catch (Throwable $e) {
        error_log('current_user_id refresh: ' . $e->getMessage());
    }

    return null;
}

/**
 * Require an authenticated user or redirect to login (with return path when possible).
 */
function require_login(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        require_once __DIR__ . '/includes/auth_redirect.php';
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($next) || !auth_redirect_uri_safe($next)) {
            $next = '/';
        }
        header('Location: /login.php?next=' . rawurlencode($next));
        exit;
    }

    return $userId;
}

/**
 * Log out: revoke refresh token(s), clear refresh cookie, destroy session, redirect to login.
 */
function auth_logout_and_redirect(): void
{
    bootstrap_session();
    $pdo = db();
    $v = $_SESSION['user_id'] ?? null;
    $uid = null;
    if (is_int($v) && $v > 0) {
        $uid = $v;
    } elseif (is_string($v) && ctype_digit($v)) {
        $uid = (int) $v;
    }
    if ($uid !== null && $uid > 0) {
        auth_refresh_token_revoke_all_for_user($pdo, $uid);
    } else {
        auth_refresh_token_revoke_cookie_only($pdo);
    }
    auth_refresh_token_clear_cookie();
    session_destroy_completely();
    header('Location: /login.php');
    exit;
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
