<?php
/**
 * group_invite_request_respond.php — Owner approves (issues invite) or declines a member request.
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
$requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

$pdo = db();

if ($groupId <= 0 || $requestId <= 0 || !user_is_group_owner($pdo, $userId, $groupId)) {
    header('Location: /groups.php');
    exit;
}

$req = group_invite_request_fetch_pending($pdo, $requestId, $groupId);
if ($req === null) {
    header('Location: /group.php?id=' . $groupId . '&invite_requests=1');
    exit;
}

if ($action === 'decline') {
    $upd = $pdo->prepare(
        'UPDATE group_invite_requests SET declined_at = NOW()
         WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
    );
    $upd->execute([$requestId]);
    header('Location: /group.php?id=' . $groupId . '&invite_requests=1&invite_request_declined=1');
    exit;
}

if ($action !== 'approve') {
    header('Location: /group.php?id=' . $groupId . '&invite_requests=1');
    exit;
}

$normalizedEmail = $req['requested_email'];
$resolvedId = invite_resolve_invited_user_id($pdo, $normalizedEmail);
if ($resolvedId !== null && user_is_group_member($pdo, $resolvedId, $groupId)) {
    $upd = $pdo->prepare(
        'UPDATE group_invite_requests SET approved_at = NOW()
         WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
    );
    $upd->execute([$requestId]);
    header('Location: /group.php?id=' . $groupId . '&invite_requests=1&invite_request_approved=already_member');
    exit;
}

if (group_issue_email_invitation($pdo, $groupId, $normalizedEmail, $userId)) {
    $upd = $pdo->prepare(
        'UPDATE group_invite_requests SET approved_at = NOW()
         WHERE id = ? AND approved_at IS NULL AND declined_at IS NULL'
    );
    $upd->execute([$requestId]);
    header('Location: /group.php?id=' . $groupId . '&invite_requests=1&invite_request_approved=1');
    exit;
}

header('Location: /group.php?id=' . $groupId . '&invite_requests=1&invite_request_err=approve_failed');
exit;
