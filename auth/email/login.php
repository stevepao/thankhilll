<?php
/**
 * Email OTP sign-in: request code and verify on one page (works across devices).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

if (current_user_id() !== null) {
    header('Location: /index.php');
    exit;
}

$showSent = isset($_GET['sent']);
$showErr = isset($_GET['err']);

$pageTitle = 'Sign in with Email';
$currentNav = '';
$showNav = false;

require_once dirname(__DIR__, 2) . '/header.php';
?>

            <?php if ($showSent): ?>
                <p class="flash" role="status">If the email is valid, a code was sent.</p>
            <?php endif; ?>

            <?php if ($showErr): ?>
                <p class="flash flash--error" role="alert">Invalid or expired code.</p>
            <?php endif; ?>

            <p class="email-auth__step-title email-auth__step-title--first">Step 1 — Send code</p>
            <form class="note-form" method="post" action="/auth/email/request_code.php">
                <?php csrf_hidden_field(); ?>
                <label class="note-form__label" for="request_email">Email</label>
                <input type="email" class="note-form__input" id="request_email" name="email" autocomplete="username">

                <button type="submit" class="btn btn--primary">Send code</button>
            </form>

            <p class="email-auth__step-title">Step 2 — Enter code</p>
            <p class="email-auth__hint">Use the same email and the 6-digit code from your inbox (any device).</p>

            <form class="note-form" method="post" action="/auth/email/verify_code.php">
                <?php csrf_hidden_field(); ?>
                <label class="note-form__label" for="verify_email">Email</label>
                <input type="email" class="note-form__input" id="verify_email" name="email" autocomplete="username">

                <label class="note-form__label" for="code">6-digit code</label>
                <input type="text" class="note-form__input" id="code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">

                <button type="submit" class="btn btn--primary">Verify and sign in</button>
            </form>

            <p class="empty-state"><a href="/login.php">Other sign-in options</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
