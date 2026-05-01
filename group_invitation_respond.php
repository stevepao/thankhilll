<?php
/**
 * group_invitation_respond.php — Accept or decline a pending group invite (in-app; POST).
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
$pdo = db();

$inviteId = isset($_POST['invitation_id']) ? (int) $_POST['invitation_id'] : 0;
$decision = isset($_POST['decision']) ? (string) $_POST['decision'] : '';

if ($inviteId <= 0 || ($decision !== 'accept' && $decision !== 'decline')) {
    header('Location: /groups.php');
    exit;
}

if ($decision === 'decline') {
    if (group_invitation_decline_for_user($pdo, $userId, $inviteId)) {
        header('Location: /groups.php?invite_declined=1');
    } else {
        header('Location: /groups.php?invite_err=1');
    }
    exit;
}

$invite = group_invitation_fetch_actionable_for_user($pdo, $inviteId, $userId);
if ($invite === null) {
    header('Location: /groups.php?invite_err=1');
    exit;
}

try {
    group_invitation_accept_transaction($pdo, $userId, $inviteId);
} catch (Throwable $e) {
    error_log('group_invitation_respond accept: ' . $e->getMessage());
    header('Location: /groups.php?invite_err=1');
    exit;
}

header('Location: /group.php?id=' . (int) $invite['group_id'] . '&joined=1');
exit;
