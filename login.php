<?php
/**
 * login.php — Simple entry page for authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/auth_redirect.php';

bootstrap_session();

if (isset($_GET['next']) && is_string($_GET['next']) && auth_redirect_uri_safe($_GET['next'])) {
    $_SESSION['auth_redirect_after_login'] = $_GET['next'];
}

if (current_user_id() !== null) {
    header('Location: ' . invite_login_redirect_path());
    exit;
}

$pageTitle = 'Thankhill — Sign in';
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

            <p class="empty-state">
                <strong>Thankhill</strong> is Hillwork’s gratitude journal—sign in to open your Today page and notes.
            </p>
            <p>
                <a class="btn btn--primary" href="/auth/google/login.php">Sign in with Google</a>
            </p>
            <p>
                <a class="btn btn--primary" href="/auth/email/login.php">Sign in with email</a>
            </p>
            <p class="login-footnote"><a href="/policy">Privacy Policy</a> · <a href="/terms">Terms of Use</a></p>

<?php require_once __DIR__ . '/footer.php'; ?>
