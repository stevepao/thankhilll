<?php
/**
 * group_leave.php — Member leaves a group (POST). Owners are rejected.
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

if ($groupId <= 0) {
    header('Location: /groups.php');
    exit;
}

$result = group_member_leave($pdo, $userId, $groupId);

if ($result === 'ok') {
    header('Location: /groups.php?left_group=1');
    exit;
}

if ($result === 'owner_blocked') {
    header('Location: /group.php?id=' . $groupId . '&leave_err=owner');
    exit;
}

header('Location: /groups.php');
exit;
