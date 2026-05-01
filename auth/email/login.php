<?php
/**
 * Email sign-in: step 1 request code, step 2 verify OTP (provider `email`).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_otp_session.php';

if (current_user_id() !== null) {
    header('Location: /index.php');
    exit;
}

if (isset($_GET['cancel'])) {
    email_otp_clear_pending();
    header('Location: /auth/email/login.php');
    exit;
}

$showSent = isset($_GET['sent']);
$showErr = isset($_GET['err']);
$stepCode = email_otp_pending_ready();

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

            <?php if (!$stepCode): ?>
                <form class="note-form" method="post" action="/auth/email/request_code.php">
                    <?php csrf_hidden_field(); ?>
                    <label class="note-form__label" for="email">Email</label>
                    <input type="email" class="note-form__input" id="email" name="email" autocomplete="username">

                    <button type="submit" class="btn btn--primary">Send code</button>
                </form>
            <?php else: ?>
                <p class="empty-state">Code sent to <?= e((string) ($_SESSION['pending_email'] ?? '')) ?></p>

                <form class="note-form" method="post" action="/auth/email/verify_code.php">
                    <?php csrf_hidden_field(); ?>
                    <label class="note-form__label" for="code">6-digit code</label>
                    <input type="text" class="note-form__input" id="code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">

                    <button type="submit" class="btn btn--primary">Verify and sign in</button>
                </form>

                <form class="note-form" method="post" action="/auth/email/request_code.php" style="margin-top: 1rem;">
                    <?php csrf_hidden_field(); ?>
                    <input type="hidden" name="email" value="<?= e((string) ($_SESSION['pending_email'] ?? '')) ?>">
                    <button type="submit" class="btn btn--primary">Resend code</button>
                </form>

                <p class="empty-state"><a href="/auth/email/login.php?cancel=1">Use a different email</a></p>
            <?php endif; ?>

            <p class="empty-state"><a href="/login.php">Other sign-in options</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
