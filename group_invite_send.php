<?php
/**
 * group_invite_send.php — Owner-only POST handler to email a group invitation.
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

if ($groupId <= 0 || !user_is_group_owner($pdo, $userId, $groupId)) {
    header('Location: /groups.php');
    exit;
}

$email = invite_normalize_email($_POST['email'] ?? null);
if ($email !== null) {
    group_issue_email_invitation($pdo, $groupId, $email, $userId);
}

header('Location: /group.php?id=' . $groupId . '&invite_sent=1');
exit;
