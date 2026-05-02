<?php
/**
 * Bounded persistent reauthentication via HttpOnly cookie + server-stored token hash.
 * Does not replace idle timeout for active sessions; restores session when cookie valid.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/session.php';

/** Cookie name for opaque refresh token (never localStorage). */
const AUTH_REFRESH_COOKIE_NAME = 'thankhill_refresh';

/** Absolute maximum lifetime for each issued refresh token (seconds). */
const AUTH_REFRESH_TOKEN_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

function auth_refresh_cookie_params(): array
{
    session_load_env_if_needed();

    $secureEnv = $_ENV['SESSION_COOKIE_SECURE'] ?? getenv('SESSION_COOKIE_SECURE');
    $secure = ($secureEnv === '1' || $secureEnv === 'true' || $secureEnv === true)
        ? true
        : session_request_is_https();

    return [
        'expires' => time() + AUTH_REFRESH_TOKEN_LIFETIME_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function auth_refresh_token_issue(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $bytes = random_bytes(32);
    $opaqueHex = bin2hex($bytes);
    $hashHex = hash('sha256', $bytes);

    $expiresAt = (new DateTimeImmutable('@' . (time() + AUTH_REFRESH_TOKEN_LIFETIME_SECONDS)))
        ->format('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO auth_refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $hashHex, $expiresAt]);
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_table($e)) {
            throw $e;
        }
        error_log('auth_refresh_token_issue: auth_refresh_tokens table missing — run migration 023.');
        return;
    }

    $p = auth_refresh_cookie_params();
    setcookie(AUTH_REFRESH_COOKIE_NAME, $opaqueHex, $p);
}

function auth_refresh_token_clear_cookie(): void
{
    $p = auth_refresh_cookie_params();
    $expire = time() - 3600;
    setcookie(AUTH_REFRESH_COOKIE_NAME, '', [
        'expires' => $expire,
        'path' => $p['path'],
        'domain' => $p['domain'],
        'secure' => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Revoke the refresh token row matching the current cookie (session already gone). */
function auth_refresh_token_revoke_cookie_only(PDO $pdo): void
{
    $raw = $_COOKIE[AUTH_REFRESH_COOKIE_NAME] ?? null;
    if (!is_string($raw) || $raw === '' || preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
        return;
    }

    $bin = hex2bin($raw);
    if ($bin === false || strlen($bin) !== 32) {
        return;
    }

    $hashHex = hash('sha256', $bin);

    try {
        $stmt = $pdo->prepare('DELETE FROM auth_refresh_tokens WHERE token_hash = ?');
        $stmt->execute([$hashHex]);
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_table($e)) {
            throw $e;
        }
    }
}

function auth_refresh_token_revoke_all_for_user(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM auth_refresh_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_table($e)) {
            throw $e;
        }
    }
}

/**
 * When session is missing or idle-expired, validate refresh cookie and call session_commit_login.
 */
function auth_refresh_token_try_restore_session(PDO $pdo): bool
{
    $raw = $_COOKIE[AUTH_REFRESH_COOKIE_NAME] ?? null;
    if (!is_string($raw) || $raw === '' || preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
        return false;
    }

    $bin = hex2bin($raw);
    if ($bin === false || strlen($bin) !== 32) {
        return false;
    }

    $hashHex = hash('sha256', $bin);

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT user_id FROM auth_refresh_tokens
            WHERE token_hash = ? AND expires_at > NOW()
            LIMIT 1
            SQL
        );
        $stmt->execute([$hashHex]);
        $col = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            return false;
        }
        throw $e;
    }

    if ($col === false) {
        auth_refresh_token_clear_cookie();
        return false;
    }

    $uid = (int) $col;
    if ($uid <= 0) {
        auth_refresh_token_clear_cookie();
        return false;
    }

    session_commit_login($uid);

    return true;
}
