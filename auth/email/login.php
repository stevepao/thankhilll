<?php
/**
 * Email OTP sign-in: send code (step 1) and verify with email + code (step 2, cross-device safe).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';

if (current_user_id() !== null) {
    header('Location: /index.php');
    exit;
}

if (isset($_GET['reset'])) {
    email_otp_session_clear_pending_email();
    header('Location: /auth/email/login.php');
    exit;
}

$showSent = isset($_GET['sent']);
$showCodeErr = isset($_GET['code_err']) || isset($_GET['err']);
$pendingEmail = email_otp_session_get_pending_email();
$emphasizeVerify = $pendingEmail !== null || $showSent;

$pageTitle = 'Sign in with Email';
$currentNav = '';
$showNav = false;

require_once dirname(__DIR__, 2) . '/header.php';

$verifyHintKnown = 'Enter the 6-digit code from your email (you can open the message on any device).';
$verifyHintBoth = 'Enter the email you used when requesting the code and the 6-digit code from your inbox.';
?>

            <?php if ($showSent): ?>
                <p class="flash" role="status">If the email is valid, a code was sent.</p>
            <?php endif; ?>

            <div class="email-auth-flow<?= $emphasizeVerify ? ' email-auth-flow--prioritize-verify' : '' ?>">

                <section class="email-auth__step email-auth__step--send<?= $emphasizeVerify ? ' email-auth__step--secondary' : '' ?>" aria-labelledby="email-auth-send-heading">
                    <h2 id="email-auth-send-heading" class="email-auth__step-title"><?= $emphasizeVerify ? 'Resend or change email' : 'Step 1 — Send code' ?></h2>
                    <?php if ($emphasizeVerify): ?>
                        <p class="email-auth__hint">Need a new code? Enter your email below. Your previous code may stop working.</p>
                    <?php endif; ?>
                    <form class="note-form" method="post" action="/auth/email/request_code.php">
                        <?php csrf_hidden_field(); ?>
                        <label class="note-form__label" for="request_email">Email</label>
                        <input
                            type="email"
                            class="note-form__input"
                            id="request_email"
                            name="email"
                            autocomplete="username"
                            value="<?= $pendingEmail !== null ? e($pendingEmail) : '' ?>"
                        >
                        <button type="submit" class="btn btn--primary">Send code</button>
                    </form>
                </section>

                <section class="email-auth__step email-auth__step--verify<?= $emphasizeVerify ? ' email-auth__step--primary' : ' email-auth__step--secondary' ?>" aria-labelledby="email-auth-verify-heading">
                    <h2 id="email-auth-verify-heading" class="email-auth__step-title">Step 2 — Enter code</h2>

                    <?php if ($showCodeErr): ?>
                        <p class="flash flash--error email-auth__code-flash" role="alert">Invalid or expired code. Try again or request a new code.</p>
                    <?php endif; ?>

                    <p class="email-auth__hint"><?= $pendingEmail !== null ? e($verifyHintKnown) : e($verifyHintBoth) ?></p>

                    <form class="note-form" method="post" action="/auth/email/verify_code.php" autocomplete="off">
                        <?php csrf_hidden_field(); ?>

                        <?php if ($pendingEmail !== null): ?>
                            <p class="note-form__label">Signing in as</p>
                            <p class="email-auth__email-readonly" aria-live="polite"><?= e($pendingEmail) ?></p>
                            <input type="hidden" name="email" value="<?= e($pendingEmail) ?>">
                        <?php else: ?>
                            <label class="note-form__label" for="verify_email">Email</label>
                            <input
                                type="email"
                                class="note-form__input"
                                id="verify_email"
                                name="email"
                                autocomplete="username"
                                required
                            >
                        <?php endif; ?>

                        <label class="note-form__label" for="code">6-digit code</label>
                        <input
                            type="text"
                            class="note-form__input"
                            id="code"
                            name="code"
                            inputmode="numeric"
                            maxlength="6"
                            pattern="[0-9]{6}"
                            autocomplete="one-time-code"
                            required
                            aria-invalid="<?= $showCodeErr ? 'true' : 'false' ?>"
                        >

                        <button type="submit" class="btn btn--primary">Verify and sign in</button>

                        <?php if ($pendingEmail !== null): ?>
                            <p class="email-auth__footnote">
                                <a href="/auth/email/login.php?reset=1">Use a different email</a>
                            </p>
                        <?php endif; ?>
                    </form>
                </section>

            </div>

            <p class="empty-state"><a href="/login.php">Other sign-in options</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
