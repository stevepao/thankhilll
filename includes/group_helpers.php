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
           AND declined_at IS NULL
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

/** Keep users.login_email_normalized in sync so invitations match existing accounts. */
function user_sync_login_email_normalized(PDO $pdo, int $userId, ?string $normalizedEmail): void
{
    if ($userId <= 0 || $normalizedEmail === null || $normalizedEmail === '') {
        return;
    }
    $stmt = $pdo->prepare('UPDATE users SET login_email_normalized = ? WHERE id = ?');
    $stmt->execute([$normalizedEmail, $userId]);
}

function invite_resolve_invited_user_id(PDO $pdo, string $normalizedEmail): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE login_email_normalized = ? LIMIT 1');
    $stmt->execute([$normalizedEmail]);
    $col = $stmt->fetchColumn();
    if ($col !== false) {
        return (int) $col;
    }

    $stmt = $pdo->prepare(
        'SELECT user_id FROM auth_identities WHERE oauth_contact_email_normalized = ? LIMIT 1'
    );
    $stmt->execute([$normalizedEmail]);
    $col = $stmt->fetchColumn();

    return $col !== false ? (int) $col : null;
}

/**
 * Pending invitations the viewer can accept or decline in-app (email is notification only).
 *
 * @return list<array{id:int,group_id:int,email:string,expires_at:string,created_at:string,group_name:string,invited_by_display_name:string}>
 */
function group_invitations_pending_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $sql = <<<'SQL'
        SELECT gi.id,
               gi.group_id,
               gi.email,
               gi.expires_at,
               gi.created_at,
               g.name AS group_name,
               inviter.display_name AS invited_by_display_name
        FROM group_invitations gi
        INNER JOIN `groups` g ON g.id = gi.group_id
        INNER JOIN users inviter ON inviter.id = gi.invited_by_user_id
        WHERE gi.accepted_at IS NULL
          AND gi.declined_at IS NULL
          AND gi.expires_at > NOW()
          AND NOT EXISTS (
              SELECT 1 FROM group_members gm
              WHERE gm.user_id = ? AND gm.group_id = gi.group_id
          )
          AND (
              gi.invited_user_id = ?
              OR EXISTS (
                  SELECT 1 FROM users u
                  WHERE u.id = ?
                    AND u.login_email_normalized IS NOT NULL
                    AND u.login_email_normalized = gi.email
              )
              OR EXISTS (
                  SELECT 1 FROM auth_identities ai
                  WHERE ai.user_id = ?
                    AND ai.oauth_contact_email_normalized IS NOT NULL
                    AND ai.oauth_contact_email_normalized = gi.email
              )
          )
        ORDER BY gi.created_at DESC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'group_id' => (int) $row['group_id'],
            'email' => (string) $row['email'],
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
            'group_name' => (string) $row['group_name'],
            'invited_by_display_name' => (string) $row['invited_by_display_name'],
        ];
    }

    return $out;
}

/**
 * @return array{id:int,group_id:int,email:string}|null
 */
function group_invitation_fetch_actionable_for_user(PDO $pdo, int $inviteId, int $userId): ?array
{
    if ($inviteId <= 0 || $userId <= 0) {
        return null;
    }

    $sql = <<<'SQL'
        SELECT gi.id, gi.group_id, gi.email
        FROM group_invitations gi
        WHERE gi.id = ?
          AND gi.accepted_at IS NULL
          AND gi.declined_at IS NULL
          AND gi.expires_at > NOW()
          AND NOT EXISTS (
              SELECT 1 FROM group_members gm
              WHERE gm.user_id = ? AND gm.group_id = gi.group_id
          )
          AND (
              gi.invited_user_id = ?
              OR EXISTS (
                  SELECT 1 FROM users u
                  WHERE u.id = ?
                    AND u.login_email_normalized IS NOT NULL
                    AND u.login_email_normalized = gi.email
              )
              OR EXISTS (
                  SELECT 1 FROM auth_identities ai
                  WHERE ai.user_id = ?
                    AND ai.oauth_contact_email_normalized IS NOT NULL
                    AND ai.oauth_contact_email_normalized = gi.email
              )
          )
        LIMIT 1
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$inviteId, $userId, $userId, $userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? [
        'id' => (int) $row['id'],
        'group_id' => (int) $row['group_id'],
        'email' => (string) $row['email'],
    ] : null;
}

/**
 * Accept invitation: add membership (idempotent on duplicate member row) and mark accepted.
 *
 * @throws RuntimeException when the invite row cannot be finalized
 */
function group_invitation_accept_transaction(PDO $pdo, int $userId, int $inviteId): void
{
    $pdo->beginTransaction();

    try {
        $chk = $pdo->prepare(
            <<<'SQL'
            SELECT group_id FROM group_invitations
            WHERE id = ?
              AND accepted_at IS NULL
              AND declined_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
            FOR UPDATE
            SQL
        );
        $chk->execute([$inviteId]);
        $locked = $chk->fetch(PDO::FETCH_ASSOC);
        if (!is_array($locked)) {
            throw new RuntimeException('Invitation is no longer available.');
        }

        $groupId = (int) $locked['group_id'];

        $mem = $pdo->prepare(
            'INSERT INTO group_members (user_id, group_id, role, joined_at)
             VALUES (?, ?, \'member\', NOW())'
        );
        try {
            $mem->execute([$userId, $groupId]);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
        }

        $upd = $pdo->prepare(
            'UPDATE group_invitations SET accepted_at = NOW() WHERE id = ? AND accepted_at IS NULL AND declined_at IS NULL'
        );
        $upd->execute([$inviteId]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Invitation already finalized.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function group_invitation_decline_for_user(PDO $pdo, int $userId, int $inviteId): bool
{
    $row = group_invitation_fetch_actionable_for_user($pdo, $inviteId, $userId);
    if ($row === null) {
        return false;
    }

    $upd = $pdo->prepare(
        'UPDATE group_invitations SET declined_at = NOW() WHERE id = ? AND accepted_at IS NULL AND declined_at IS NULL'
    );
    $upd->execute([$inviteId]);

    return $upd->rowCount() === 1;
}

/** Best-effort address to notify a user (login email or email OTP identity). */
function user_notification_email(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT login_email_normalized FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    if (is_string($v) && $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false) {
        return $v;
    }

    $stmt = $pdo->prepare(
        'SELECT oauth_contact_email_normalized FROM auth_identities
         WHERE user_id = ? AND oauth_contact_email_normalized IS NOT NULL AND oauth_contact_email_normalized <> \'\'
         ORDER BY last_used_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $oauthMail = $stmt->fetchColumn();
    if (is_string($oauthMail) && $oauthMail !== '' && filter_var($oauthMail, FILTER_VALIDATE_EMAIL) !== false) {
        return strtolower(trim($oauthMail));
    }

    $stmt = $pdo->prepare(
        'SELECT identifier FROM auth_identities WHERE user_id = ? AND provider = ? LIMIT 1'
    );
    $stmt->execute([$userId, 'email']);
    $idf = $stmt->fetchColumn();
    if (is_string($idf) && filter_var($idf, FILTER_VALIDATE_EMAIL) !== false) {
        return strtolower(trim($idf));
    }

    return null;
}

/** True if this normalized email belongs to the user (invitation self-match checks). */
function user_matches_normalized_email(PDO $pdo, int $userId, string $normalizedEmail): bool
{
    if ($userId <= 0 || $normalizedEmail === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT login_email_normalized FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    if (is_string($v) && $v === $normalizedEmail) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM auth_identities WHERE user_id = ? AND oauth_contact_email_normalized = ? LIMIT 1'
    );
    $stmt->execute([$userId, $normalizedEmail]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM auth_identities WHERE user_id = ? AND provider = ? AND identifier = ? LIMIT 1'
    );
    $stmt->execute([$userId, 'email', $normalizedEmail]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Create a group_invitations row and email the invitee (same behavior as owner “Invite by email”).
 */
function group_issue_email_invitation(PDO $pdo, int $groupId, string $normalizedEmail, int $invitedByUserId): bool
{
    require_once __DIR__ . '/mailer.php';

    try {
        $token = invite_new_token();
        $invitedUserId = invite_resolve_invited_user_id($pdo, $normalizedEmail);

        $ins = $pdo->prepare(
            'INSERT INTO group_invitations (group_id, email, token, invited_by_user_id, invited_user_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())'
        );
        $ins->execute([$groupId, $normalizedEmail, $token, $invitedByUserId, $invitedUserId]);

        $gStmt = $pdo->prepare('SELECT name FROM `groups` WHERE id = ? LIMIT 1');
        $gStmt->execute([$groupId]);
        $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
        $groupName = is_array($gRow) ? (string) $gRow['name'] : 'a group';

        $link = app_absolute_url('/invite/accept.php?token=' . rawurlencode($token));
        $body = "You’ve been invited to join the gratitude group «{$groupName}» on Thank Hill.\n\n";
        if ($invitedUserId !== null) {
            $body .= "If you’re already signed in, open the app and go to Groups → Pending invitations to accept or decline.\n\n";
        }
        $body .= "Or open this link to accept (single use, expires in 7 days):\n{$link}\n";

        return send_email($normalizedEmail, 'Group invitation', $body);
    } catch (Throwable $e) {
        error_log('group_issue_email_invitation: ' . $e->getMessage());

        return false;
    }
}

function group_invite_request_pending_exists(PDO $pdo, int $groupId, string $normalizedEmail): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM group_invite_requests
         WHERE group_id = ?
           AND requested_email = ?
           AND approved_at IS NULL
           AND declined_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$groupId, $normalizedEmail]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<array{id:int,requested_email:string,created_at:string,requester_display_name:string}>
 */
function group_invite_requests_pending_for_group(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT r.id,
               r.requested_email,
               r.created_at,
               u.display_name AS requester_display_name
        FROM group_invite_requests r
        INNER JOIN users u ON u.id = r.requester_user_id
        WHERE r.group_id = ?
          AND r.approved_at IS NULL
          AND r.declined_at IS NULL
        ORDER BY r.created_at ASC
        SQL
    );
    $stmt->execute([$groupId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'requested_email' => (string) $row['requested_email'],
            'created_at' => (string) $row['created_at'],
            'requester_display_name' => (string) $row['requester_display_name'],
        ];
    }

    return $out;
}

/** Count of pending member-initiated invite requests for groups this user owns (nav badge). */
function group_invite_requests_pending_count_for_owner(PDO $pdo, int $ownerUserId): int
{
    if ($ownerUserId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT COUNT(*)
        FROM group_invite_requests r
        INNER JOIN `groups` g ON g.id = r.group_id AND g.owner_user_id = ?
        WHERE r.approved_at IS NULL AND r.declined_at IS NULL
        SQL
    );
    $stmt->execute([$ownerUserId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Pending invite requests per group for groups this user owns (e.g. group list badges).
 *
 * @return array<int, int> group_id => count
 */
function group_invite_requests_pending_counts_for_owner_groups(PDO $pdo, int $ownerUserId): array
{
    if ($ownerUserId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT r.group_id, COUNT(*) AS c
        FROM group_invite_requests r
        INNER JOIN `groups` g ON g.id = r.group_id AND g.owner_user_id = ?
        WHERE r.approved_at IS NULL AND r.declined_at IS NULL
        GROUP BY r.group_id
        SQL
    );
    $stmt->execute([$ownerUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['group_id']] = (int) $row['c'];
    }

    return $out;
}

/** @return array{id:int,group_id:int,requested_email:string}|null */
function group_invite_request_fetch_pending(PDO $pdo, int $requestId, int $groupId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, group_id, requested_email FROM group_invite_requests
         WHERE id = ?
           AND group_id = ?
           AND approved_at IS NULL
           AND declined_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$requestId, $groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? [
        'id' => (int) $row['id'],
        'group_id' => (int) $row['group_id'],
        'requested_email' => (string) $row['requested_email'],
    ] : null;
}

function group_notify_owner_invite_request(
    PDO $pdo,
    int $groupId,
    int $ownerUserId,
    string $requesterDisplayName,
    string $requestedEmail,
): void {
    require_once __DIR__ . '/mailer.php';

    $to = user_notification_email($pdo, $ownerUserId);
    if ($to === null) {
        error_log('group_notify_owner_invite_request: no notification email for owner user ' . $ownerUserId);

        return;
    }

    $gStmt = $pdo->prepare('SELECT name FROM `groups` WHERE id = ? LIMIT 1');
    $gStmt->execute([$groupId]);
    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    $groupName = is_array($gRow) ? (string) $gRow['name'] : 'your group';

    $reviewUrl = app_absolute_url('/group.php?id=' . $groupId . '&invite_requests=1#pending-invite-requests');
    $subject = 'Invite request for «' . $groupName . '»';
    $body = "{$requesterDisplayName} asked you to invite {$requestedEmail} to «{$groupName}».\n\n"
        . "Open the group in Thank Hill to approve or ignore:\n{$reviewUrl}\n";

    send_email($to, $subject, $body);
}
