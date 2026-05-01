<?php
/**
 * login.php — Simple entry page for authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (current_user_id() !== null) {
    if (!empty($_SESSION['invite_pending_token'])) {
        header('Location: /invite/accept.php');
        exit;
    }
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Sign In';
$currentNav = '';
$showNav = false;
$accountDeleted = isset($_GET['account_deleted']);

require_once __DIR__ . '/header.php';
?>

            <?php if ($accountDeleted): ?>
                <p class="flash" role="status">
                    Your account has been permanently deleted.
                    If you had signed in with Google, this app’s access was revoked when we could use your stored token—you can still manage third-party connections under your Google Account.
                </p>
            <?php endif; ?>

            <p class="empty-state">Sign in to access your journal.</p>
            <p>
                <a class="btn btn--primary" href="/auth/google/login.php">Sign in with Google</a>
            </p>
            <p>
                <a class="btn btn--primary" href="/auth/email/login.php">Sign in with email</a>
            </p>

<?php require_once __DIR__ . '/footer.php'; ?>
