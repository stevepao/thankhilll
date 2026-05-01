<?php
/**
 * group_delete.php — Owner deletes a group (POST). Cascades sharing links only.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/user_preferences.php';

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

if (group_delete_by_owner($pdo, $userId, $groupId)) {
    user_preferences_strip_last_used_group_id($pdo, $userId, $groupId);
    header('Location: /groups.php?group_deleted=1');
    exit;
}

header('Location: /groups.php');
exit;
