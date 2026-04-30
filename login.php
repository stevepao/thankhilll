<?php
/**
 * login.php — Simple entry page for authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (currentUserId() !== null) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Sign In';
$currentNav = '';
$showNav = false;

require_once __DIR__ . '/header.php';
?>

            <p class="empty-state">Sign in to access your journal.</p>
            <p>
                <a class="btn btn--primary" href="/auth/google/login.php">Sign in with Google</a>
            </p>

<?php require_once __DIR__ . '/footer.php'; ?>
