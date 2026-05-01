<?php
/**
 * POST delete own comment (same calendar day only).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/note_access.php';
require_once dirname(__DIR__) . '/includes/thought_comments.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /notes.php');
    exit;
}

$userId = require_login();
csrf_verify_post_or_abort();

$pdo = db();
$viewerTz = user_timezone_get($pdo, $userId);

$redirect = thought_comment_redirect_target($_POST['redirect'] ?? null);
$commentId = (int) ($_POST['comment_id'] ?? 0);

$failRedirect = static function () use ($redirect): void {
    header('Location: ' . thought_comment_redirect_with_param($redirect, 'comment_err=1'));
    exit;
};

if ($commentId <= 0) {
    $failRedirect();
}

$stmt = $pdo->prepare(
    <<<'SQL'
    SELECT c.id, c.user_id, c.thought_id, c.created_at
    FROM thought_comments c
    WHERE c.id = ?
    LIMIT 1
    SQL
);
$stmt->execute([$commentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    $failRedirect();
}

if ((int) $row['user_id'] !== $userId) {
    $failRedirect();
}

if (!thought_comment_delete_window_open((string) $row['created_at'], $viewerTz)) {
    $failRedirect();
}

$thoughtId = (int) $row['thought_id'];
if (!user_can_view_thought($pdo, $userId, $thoughtId)) {
    $failRedirect();
}

$pdo->prepare('DELETE FROM thought_comments WHERE id = ? AND user_id = ?')->execute([$commentId, $userId]);

header('Location: ' . thought_comment_redirect_with_param($redirect, 'comment_deleted=1'));
exit;
