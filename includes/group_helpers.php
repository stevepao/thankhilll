<?php
/**
 * includes/group_helpers.php — Groups, memberships, invites, sharing helpers.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/email_auth.php';
require_once __DIR__ . '/session.php';

/** Absolute URL for same-origin paths (invite links). */
function app_absolute_url(string $path): string
{
    $scheme = session_request_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';

    return $scheme . '://' . $host . $path;
}

/** Redirect target after login when finishing invite acceptance. */
function invite_login_redirect_path(): string
{
    bootstrap_session();

    return !empty($_SESSION['invite_pending_token']) ? '/invite/accept.php' : '/index.php';
}

/** Normalize invite email; returns null if invalid (caller treats as soft-fail). */
function invite_normalize_email(mixed $raw): ?string
{
    return email_auth_normalize($raw);
}

/** Cryptographically strong single-use invite token (stored hashed-safe as opaque string). */
function invite_new_token(): string
{
    return bin2hex(random_bytes(32));
}

function group_token_format_ok(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

function user_is_group_member(PDO $pdo, int $userId, int $groupId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM group_members WHERE user_id = ? AND group_id = ? LIMIT 1'
    );
    $stmt->execute([$userId, $groupId]);

    return (bool) $stmt->fetchColumn();
}

function user_is_group_owner(PDO $pdo, int $userId, int $groupId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM `groups` WHERE id = ? AND owner_user_id = ? LIMIT 1'
    );
    $stmt->execute([$groupId, $userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Groups the user belongs to, with member counts.
 *
 * @return list<array{id:int,name:string,member_count:int}>
 */
function groups_for_user_with_counts(PDO $pdo, int $userId): array
{
    $sql = <<<'SQL'
        SELECT g.id, g.name, COUNT(gm2.id) AS member_count
        FROM `groups` g
        INNER JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
        INNER JOIN group_members gm2 ON gm2.group_id = g.id
        GROUP BY g.id, g.name
        ORDER BY g.name ASC
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'member_count' => (int) $row['member_count'],
        ];
    }, $rows);
}

/** @return ?array{id:int,group_id:int,email:string} */
function invite_find_pending_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, group_id, email FROM group_invitations
         WHERE token = ?
           AND accepted_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? [
        'id' => (int) $row['id'],
        'group_id' => (int) $row['group_id'],
        'email' => (string) $row['email'],
    ] : null;
}
