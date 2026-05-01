<?php
/**
 * invite/accept.php — Accept a group invitation (login required; resumes after auth).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/group_helpers.php';

bootstrap_session();

$pdo = db();

function invite_accept_render_message(string $title, string $message, bool $showLoginLink): void
{
    $pageTitle = $title;
    $currentNav = '';
    $showNav = false;
    require_once dirname(__DIR__) . '/header.php';
    echo '<p class="empty-state">' . e($message) . '</p>';
    if ($showLoginLink) {
        echo '<p><a class="btn btn--primary" href="/login.php">Sign in</a></p>';
    }
    require_once dirname(__DIR__) . '/footer.php';
}

$tokenGet = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

$userId = current_user_id();

if ($userId === null) {
    if ($tokenGet === '' || !group_token_format_ok($tokenGet)) {
        invite_accept_render_message(
            'Invitation',
            'This invitation link is not valid.',
            true
        );
        exit;
    }

    $invite = invite_find_pending_by_token($pdo, $tokenGet);
    if ($invite === null) {
        invite_accept_render_message(
            'Invitation',
            'This invitation link is not valid.',
            true
        );
        exit;
    }

    $_SESSION['invite_pending_token'] = $tokenGet;
    header('Location: /login.php');
    exit;
}

$token = $tokenGet !== '' ? $tokenGet : (isset($_SESSION['invite_pending_token']) ? (string) $_SESSION['invite_pending_token'] : '');
if ($token === '' || !group_token_format_ok($token)) {
    unset($_SESSION['invite_pending_token']);
    invite_accept_render_message(
        'Invitation',
        'This invitation link is not valid.',
        false
    );
    exit;
}

$invite = invite_find_pending_by_token($pdo, $token);
if ($invite === null) {
    unset($_SESSION['invite_pending_token']);
    invite_accept_render_message(
        'Invitation',
        'This invitation link is not valid or has already been used.',
        false
    );
    exit;
}

$groupId = $invite['group_id'];
$inviteId = $invite['id'];

$pdo->beginTransaction();

try {
    $mem = $pdo->prepare(
        'INSERT INTO group_members (user_id, group_id, role, joined_at)
         VALUES (?, ?, \'member\', NOW())'
    );
    try {
        $mem->execute([$userId, $groupId]);
    } catch (PDOException $e) {
        $sqlState = $e->errorInfo[0] ?? '';
        if ($sqlState !== '23000') {
            throw $e;
        }
    }

    $upd = $pdo->prepare(
        'UPDATE group_invitations SET accepted_at = NOW() WHERE id = ? AND accepted_at IS NULL'
    );
    $upd->execute([$inviteId]);
    if ($upd->rowCount() !== 1) {
        throw new RuntimeException('Invitation already accepted.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('invite accept: ' . $e->getMessage());
    unset($_SESSION['invite_pending_token']);
    invite_accept_render_message(
        'Invitation',
        'This invitation could not be accepted. Please ask for a new invitation.',
        false
    );
    exit;
}

unset($_SESSION['invite_pending_token']);

header('Location: /group.php?id=' . $groupId . '&joined=1');
exit;
