<?php
/**
 * group_invite_request_submit.php — Member (non-owner) asks admin to invite an email.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/group_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /groups.php');
    exit;
}

csrf_verify_post_or_abort();

$userId = require_login();
$groupId = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

$pdo = db();

if ($groupId <= 0 || !user_is_group_member($pdo, $userId, $groupId) || user_is_group_owner($pdo, $userId, $groupId)) {
    header('Location: /groups.php');
    exit;
}

$email = invite_normalize_email($_POST['email'] ?? null);
if ($email === null) {
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=invalid');
    exit;
}

if (user_matches_normalized_email($pdo, $userId, $email)) {
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=self');
    exit;
}

$resolvedId = invite_resolve_invited_user_id($pdo, $email);
if ($resolvedId !== null && user_is_group_member($pdo, $resolvedId, $groupId)) {
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=already_member');
    exit;
}

if (group_invite_request_pending_exists($pdo, $groupId, $email)) {
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=duplicate');
    exit;
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
$pInv->execute([$groupId, $email]);
if ($pInv->fetchColumn()) {
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=pending_invite');
    exit;
}

$oStmt = $pdo->prepare('SELECT owner_user_id FROM `groups` WHERE id = ? LIMIT 1');
$oStmt->execute([$groupId]);
$ownerUserId = (int) $oStmt->fetchColumn();
if ($ownerUserId <= 0) {
    header('Location: /groups.php');
    exit;
}

$nStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
$nStmt->execute([$userId]);
$requesterName = $nStmt->fetchColumn();
$requesterDisplay = is_string($requesterName) && $requesterName !== '' ? $requesterName : 'A member';

try {
    $ins = $pdo->prepare(
        'INSERT INTO group_invite_requests (group_id, requester_user_id, requested_email)
         VALUES (?, ?, ?)'
    );
    $ins->execute([$groupId, $userId, $email]);
} catch (Throwable $e) {
    error_log('group_invite_request_submit: ' . $e->getMessage());
    header('Location: /group.php?id=' . $groupId . '&invite_request_err=invalid');
    exit;
}

group_notify_owner_invite_request($pdo, $groupId, $ownerUserId, $requesterDisplay, $email);

header('Location: /group.php?id=' . $groupId . '&invite_request_sent=1');
exit;
