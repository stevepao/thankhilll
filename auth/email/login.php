<?php
/**
 * Email + PIN sign-in form (provider `email`). POST is handled by submit.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

if (current_user_id() !== null) {
    header('Location: /index.php');
    exit;
}

$showInvalid = isset($_GET['err']);
$showRegistered = isset($_GET['registered']);

$pageTitle = 'Sign in with Email';
$currentNav = '';
$showNav = false;

require_once dirname(__DIR__, 2) . '/header.php';
?>

            <?php if ($showRegistered): ?>
                <p class="flash" role="status">Registration complete. You can sign in below.</p>
            <?php endif; ?>

            <?php if ($showInvalid): ?>
                <p class="flash flash--error" role="alert">Could not sign you in. Check your email and PIN.</p>
            <?php endif; ?>

            <form class="note-form" method="post" action="/auth/email/submit.php">
                <?php csrf_hidden_field(); ?>
                <label class="note-form__label" for="email">Email</label>
                <input type="email" class="note-form__input" id="email" name="email" autocomplete="username">

                <label class="note-form__label" for="pin">PIN</label>
                <input type="password" class="note-form__input" id="pin" name="pin" autocomplete="current-password">

                <button type="submit" class="btn btn--primary">Sign in</button>
            </form>

            <p class="empty-state"><a href="/login.php">Other sign-in options</a> · <a href="/auth/email/register.php">Create an account</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
