<?php
/**
 * account_delete.php — POST: permanently delete the signed-in account.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/account_delete.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /me.php');
    exit;
}

csrf_verify_post_or_abort();

$userId = require_login();

if (($_POST['understand'] ?? '') !== '1') {
    header('Location: /me.php?delete_err=understand');
    exit;
}

if (trim((string) ($_POST['confirmation'] ?? '')) !== 'DELETE') {
    header('Location: /me.php?delete_err=confirm');
    exit;
}

$pdo = db();
if (!account_delete_user_completely($pdo, $userId)) {
    header('Location: /me.php?delete_err=failed');
    exit;
}

session_destroy_completely();
header('Location: /login.php?account_deleted=1');
exit;
