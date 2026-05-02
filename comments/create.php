<?php
/**
 * POST create comment on a shared, non-private thought (within time window).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/note_access.php';
require_once dirname(__DIR__) . '/includes/thought_comments.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';
require_once dirname(__DIR__) . '/includes/PushService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /notes.php');
    exit;
}

$userId = require_login();
csrf_verify_post_or_abort();

$pdo = db();
$viewerTz = user_timezone_get($pdo, $userId);

$redirect = thought_comment_redirect_target($_POST['redirect'] ?? null);
$thoughtId = (int) ($_POST['thought_id'] ?? 0);
$bodyRaw = $_POST['body'] ?? null;

$failRedirect = static function () use ($redirect): void {
    header('Location: ' . thought_comment_redirect_with_param($redirect, 'comment_err=1'));
    exit;
};

if ($thoughtId <= 0 || !user_can_view_thought($pdo, $userId, $thoughtId)) {
    $failRedirect();
}
$meta = thought_comment_row_meta($pdo, $thoughtId);
if ($meta === null) {
    $failRedirect();
}

if ($meta['is_private'] || !note_is_shared_with_any_group($pdo, $meta['note_id'])) {
    $failRedirect();
}

if (!thought_comment_post_window_open($meta['thought_created_at'], $viewerTz)) {
    $failRedirect();
}

$validated = thought_comment_validate_body($bodyRaw);
if (!$validated['ok']) {
    $failRedirect();
}

$pdo->beginTransaction();
try {
    $lockThought = $pdo->prepare('SELECT id FROM note_thoughts WHERE id = ? LIMIT 1 FOR UPDATE');
    $lockThought->execute([$thoughtId]);
    if ((int) $lockThought->fetchColumn() <= 0) {
        $pdo->rollBack();
        $failRedirect();
    }

    $meta2 = thought_comment_row_meta($pdo, $thoughtId);
    if ($meta2 === null || $meta2['is_private'] || !note_is_shared_with_any_group($pdo, $meta2['note_id'])
        || !thought_comment_post_window_open($meta2['thought_created_at'], $viewerTz)) {
        $pdo->rollBack();
        $failRedirect();
    }

    $ins = $pdo->prepare(
        'INSERT INTO thought_comments (thought_id, user_id, body, created_at) VALUES (?, ?, ?, NOW())'
    );
    $ins->execute([$thoughtId, $userId, $validated['value']]);
    $newCommentId = (int) $pdo->lastInsertId();

    $pdo->commit();

    push_service_notify_comment_author($pdo, $newCommentId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('thought_comments create: ' . $e->getMessage());
    $failRedirect();
}

header('Location: ' . thought_comment_redirect_with_param($redirect, 'comment_added=1'));
exit;
