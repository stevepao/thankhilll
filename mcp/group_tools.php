<?php
/**
 * MCP tools — group discovery and membership (uses existing tables only).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/group_helpers.php';

const TH_MCP_GROUP_TOOLS_JSON = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * @param array<string,mixed> $payload
 * @return array{text:string,is_error:bool}
 */
function th_mcp_group_tool_ok(array $payload): array
{
    return [
        'text' => json_encode($payload, TH_MCP_GROUP_TOOLS_JSON),
        'is_error' => false,
    ];
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_group_tool_err(string $message): array
{
    return [
        'text' => json_encode(['error' => $message], TH_MCP_GROUP_TOOLS_JSON),
        'is_error' => true,
    ];
}

function th_mcp_parse_uint_string(mixed $raw): ?int
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    if (!ctype_digit($raw)) {
        return null;
    }
    $n = (int) $raw;

    return $n > 0 ? $n : null;
}

/** Owner or group_members.role = admin (case-insensitive). */
function th_mcp_user_is_group_admin(PDO $pdo, int $userId, int $groupId): bool
{
    if ($groupId <= 0 || $userId <= 0) {
        return false;
    }
    if (user_is_group_owner($pdo, $userId, $groupId)) {
        return true;
    }
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM group_members WHERE user_id = ? AND group_id = ? LIMIT 1'
    );
    $st->execute([$userId, $groupId]);
    $r = $st->fetchColumn();

    return is_string($r) && $r === 'admin';
}

/**
 * @return 'admin'|'member'
 */
function th_mcp_member_role_label(PDO $pdo, int $memberUserId, int $groupId, string $dbRole): string
{
    $st = $pdo->prepare('SELECT owner_user_id FROM `groups` WHERE id = ? LIMIT 1');
    $st->execute([$groupId]);
    $owner = (int) $st->fetchColumn();
    if ($memberUserId === $owner) {
        return 'admin';
    }
    if (strtolower(trim($dbRole)) === 'admin') {
        return 'admin';
    }

    return 'member';
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_list_my_groups_run(PDO $pdo, int $userId, array $arguments): array
{
    unset($arguments);
    $sql = <<<'SQL'
        SELECT g.id,
               g.name,
               g.owner_user_id,
               gm.role,
               (SELECT COUNT(*) FROM group_members gm2 WHERE gm2.group_id = g.id) AS member_count
        FROM `groups` g
        INNER JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
        ORDER BY g.name ASC
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $gid = (int) ($row['id'] ?? 0);
        if ($gid <= 0) {
            continue;
        }
        $role = th_mcp_member_role_label(
            $pdo,
            $userId,
            $gid,
            (string) ($row['role'] ?? 'member')
        );
        $out[] = [
            'group_id' => (string) $gid,
            'name' => (string) ($row['name'] ?? ''),
            'role' => $role,
            'member_count' => (int) ($row['member_count'] ?? 0),
        ];
    }

    return th_mcp_group_tool_ok(['groups' => $out]);
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_list_group_members_run(PDO $pdo, int $userId, array $arguments): array
{
    $gid = th_mcp_parse_uint_string($arguments['group_id'] ?? null);
    if ($gid === null) {
        return th_mcp_group_tool_err('Invalid group_id.');
    }
    if (!user_is_group_member($pdo, $userId, $gid)) {
        return th_mcp_group_tool_err('You are not a member of this group.');
    }

    $sql = <<<'SQL'
        SELECT gm.user_id, u.display_name, gm.role
        FROM group_members gm
        INNER JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at ASC, u.id ASC
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$gid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $members = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = (int) ($row['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $members[] = [
            'user_id' => (string) $uid,
            'display_name' => (string) ($row['display_name'] ?? ''),
            'role' => th_mcp_member_role_label($pdo, $uid, $gid, (string) ($row['role'] ?? 'member')),
        ];
    }

    return th_mcp_group_tool_ok(['members' => $members]);
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_request_group_member_add_run(PDO $pdo, int $userId, array $arguments): array
{
    $gid = th_mcp_parse_uint_string($arguments['group_id'] ?? null);
    if ($gid === null) {
        return th_mcp_group_tool_err('Invalid group_id.');
    }
    $emailRaw = $arguments['email'] ?? null;
    if (!is_string($emailRaw)) {
        return th_mcp_group_tool_err('Invalid email.');
    }
    $normalized = invite_normalize_email($emailRaw);
    if ($normalized === null) {
        return th_mcp_group_tool_err('Invalid email address.');
    }

    if (!user_is_group_member($pdo, $userId, $gid)) {
        return th_mcp_group_tool_err('You are not a member of this group.');
    }

    if (user_matches_normalized_email($pdo, $userId, $normalized)) {
        return th_mcp_group_tool_err('You cannot add yourself.');
    }

    $resolvedId = invite_resolve_invited_user_id($pdo, $normalized);
    if ($resolvedId !== null && user_is_group_member($pdo, $resolvedId, $gid)) {
        return th_mcp_group_tool_ok([
            'status' => 'completed',
            'detail' => 'That user is already a member.',
        ]);
    }

    if (th_mcp_user_is_group_admin($pdo, $userId, $gid)) {
        $pInv = $pdo->prepare(
            'SELECT 1 FROM group_invitations
             WHERE group_id = ?
               AND email = ?
               AND accepted_at IS NULL
               AND declined_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $pInv->execute([$gid, $normalized]);
        if ($pInv->fetchColumn()) {
            return th_mcp_group_tool_ok([
                'status' => 'completed',
                'detail' => 'An invitation is already pending for that email.',
            ]);
        }

        $ok = group_issue_email_invitation($pdo, $gid, $normalized, $userId);
        if (!$ok) {
            return th_mcp_group_tool_err('Could not send invitation; try again later.');
        }

        return th_mcp_group_tool_ok([
            'status' => 'completed',
            'detail' => 'Invitation sent.',
        ]);
    }

    if (group_invite_request_pending_exists($pdo, $gid, $normalized)) {
        return th_mcp_group_tool_ok([
            'status' => 'pending',
            'detail' => 'A pending request already exists for that email.',
        ]);
    }

    $pInv = $pdo->prepare(
        'SELECT 1 FROM group_invitations
         WHERE group_id = ?
           AND email = ?
           AND accepted_at IS NULL
           AND declined_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $pInv->execute([$gid, $normalized]);
    if ($pInv->fetchColumn()) {
        return th_mcp_group_tool_ok([
            'status' => 'pending',
            'detail' => 'An invitation is already pending for that email.',
        ]);
    }

    $oStmt = $pdo->prepare('SELECT owner_user_id FROM `groups` WHERE id = ? LIMIT 1');
    $oStmt->execute([$gid]);
    $ownerUserId = (int) $oStmt->fetchColumn();
    if ($ownerUserId <= 0) {
        return th_mcp_group_tool_err('Group has no owner; cannot submit request.');
    }

    try {
        $ins = $pdo->prepare(
            'INSERT INTO group_invite_requests (group_id, requester_user_id, requested_email)
             VALUES (?, ?, ?)'
        );
        $ins->execute([$gid, $userId, $normalized]);
    } catch (Throwable $e) {
        error_log('th_mcp_request_group_member_add_run: ' . $e->getMessage());

        return th_mcp_group_tool_err('Could not create invite request.');
    }

    $nStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $nStmt->execute([$userId]);
    $requesterName = $nStmt->fetchColumn();
    $requesterDisplay = is_string($requesterName) && $requesterName !== ''
        ? $requesterName
        : 'A member';

    group_notify_owner_invite_request($pdo, $gid, $ownerUserId, $requesterDisplay, $normalized);

    return th_mcp_group_tool_ok([
        'status' => 'pending',
        'detail' => 'Request sent to the group admin.',
    ]);
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_list_group_join_requests_run(PDO $pdo, int $userId, array $arguments): array
{
    $gid = th_mcp_parse_uint_string($arguments['group_id'] ?? null);
    if ($gid === null) {
        return th_mcp_group_tool_err('Invalid group_id.');
    }
    if (!th_mcp_user_is_group_admin($pdo, $userId, $gid)) {
        return th_mcp_group_tool_err('Only a group admin can list invite requests.');
    }

    $pending = group_invite_requests_pending_for_group($pdo, $gid);
    $requests = [];
    foreach ($pending as $r) {
        $requests[] = [
            'request_id' => (string) $r['id'],
            'requested_email' => $r['requested_email'],
            'created_at' => $r['created_at'],
            'requester_display_name' => $r['requester_display_name'],
        ];
    }

    return th_mcp_group_tool_ok(['requests' => $requests]);
}

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_review_group_join_request_run(PDO $pdo, int $userId, array $arguments): array
{
    $gid = th_mcp_parse_uint_string($arguments['group_id'] ?? null);
    if ($gid === null) {
        return th_mcp_group_tool_err('Invalid group_id.');
    }
    $reqId = th_mcp_parse_uint_string($arguments['request_id'] ?? null);
    if ($reqId === null) {
        return th_mcp_group_tool_err('Invalid request_id.');
    }
    $action = $arguments['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['approve', 'reject'], true)) {
        return th_mcp_group_tool_err('Invalid action; use approve or reject.');
    }

    if (!th_mcp_user_is_group_admin($pdo, $userId, $gid)) {
        return th_mcp_group_tool_err('Only a group admin can review invite requests.');
    }

    $req = group_invite_request_fetch_pending($pdo, $reqId, $gid);
    if ($req === null) {
        return th_mcp_group_tool_err('Request not found or already resolved.');
    }

    if ($action === 'reject') {
        $upd = $pdo->prepare(
            'UPDATE group_invite_requests SET declined_at = NOW()
             WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
        );
        $upd->execute([$reqId]);
        if ($upd->rowCount() !== 1) {
            return th_mcp_group_tool_err('Could not reject request.');
        }

        return th_mcp_group_tool_ok([
            'status' => 'completed',
            'detail' => 'Request rejected.',
        ]);
    }

    $normalizedEmail = $req['requested_email'];
    $resolvedId = invite_resolve_invited_user_id($pdo, $normalizedEmail);
    if ($resolvedId !== null && user_is_group_member($pdo, $resolvedId, $gid)) {
        $upd = $pdo->prepare(
            'UPDATE group_invite_requests SET approved_at = NOW()
             WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
        );
        $upd->execute([$reqId]);
        if ($upd->rowCount() !== 1) {
            return th_mcp_group_tool_err('Could not finalize request.');
        }

        return th_mcp_group_tool_ok([
            'status' => 'completed',
            'detail' => 'User is already a member; request closed.',
        ]);
    }

    if (!group_issue_email_invitation($pdo, $gid, $normalizedEmail, $userId)) {
        return th_mcp_group_tool_err('Could not send invitation; try again later.');
    }

    $upd = $pdo->prepare(
        'UPDATE group_invite_requests SET approved_at = NOW()
         WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
    );
    $upd->execute([$reqId]);
    if ($upd->rowCount() !== 1) {
        return th_mcp_group_tool_err('Invitation sent but request row could not be updated.');
    }

    return th_mcp_group_tool_ok([
        'status' => 'completed',
        'detail' => 'Invitation sent.',
    ]);
}
