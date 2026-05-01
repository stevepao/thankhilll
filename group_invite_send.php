<?php
/**
 * group_invite_send.php — Owner-only POST handler to email a group invitation.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/mailer.php';

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
    try {
        $token = invite_new_token();

        $ins = $pdo->prepare(
            'INSERT INTO group_invitations (group_id, email, token, invited_by_user_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())'
        );
        $ins->execute([$groupId, $email, $token, $userId]);

        $gStmt = $pdo->prepare('SELECT name FROM `groups` WHERE id = ? LIMIT 1');
        $gStmt->execute([$groupId]);
        $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
        $groupName = is_array($gRow) ? (string) $gRow['name'] : 'a group';

        $link = app_absolute_url('/invite/accept.php?token=' . rawurlencode($token));
        $body = "You’ve been invited to join the gratitude group «{$groupName}» on Thank Hill.\n\n"
            . "Open this link to accept (single use, expires in 7 days):\n{$link}\n";

        send_email($email, 'Group invitation', $body);
    } catch (Throwable $e) {
        error_log('group_invite_send: ' . $e->getMessage());
    }
}

header('Location: /group.php?id=' . $groupId . '&invite_sent=1');
exit;
