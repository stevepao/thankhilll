<?php
/**
 * Email OTP sign-in: send code (step 1) and verify with email + code (step 2, cross-device safe).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/group_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/auth_redirect.php';

bootstrap_session();

if (isset($_GET['next']) && is_string($_GET['next']) && auth_redirect_uri_safe($_GET['next'])) {
    $_SESSION['auth_redirect_after_login'] = $_GET['next'];
}

if (current_user_id() !== null) {
    header('Location: ' . invite_login_redirect_path());
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
$verifyExplicit = array_key_exists('verify', $_GET);
/** Step 2 (verify only): pending session, just sent a code, or explicit cross-device path */
$showVerifyStep = $pendingEmail !== null || $showSent || $verifyExplicit;

$pageTitle = 'Sign in with Email';
$currentNav = '';
$showNav = false;

require_once dirname(__DIR__, 2) . '/header.php';

$verifyHintKnown = 'Enter the 6-digit code below. You can open the message on any device.';
$verifyHintBoth = 'Enter the email you used to request the code and the 6-digit code from your inbox.';
?>

            <?php if ($showSent && $showVerifyStep): ?>
                <p class="flash" role="status">If the email is valid, a code was sent.</p>
            <?php endif; ?>

            <?php if (!$showVerifyStep): ?>
                <section class="email-auth email-auth--send" aria-labelledby="email-auth-send-heading">
                    <h2 id="email-auth-send-heading" class="email-auth__headline email-auth__headline--send">We'll email you a sign-in code</h2>
                    <p class="email-auth__hint">Enter your email address and we'll send a 6-digit code.</p>
                    <form class="note-form" method="post" action="/auth/email/request_code.php">
                        <?php csrf_hidden_field(); ?>
                        <label class="note-form__label" for="request_email">Email</label>
                        <input
                            type="email"
                            class="note-form__input"
                            id="request_email"
                            name="email"
                            autocomplete="username"
                            value=""
                        >
                        <button type="submit" class="btn btn--primary">Send code</button>
                    </form>
                    <p class="email-auth__alt"><a href="/auth/email/login.php?verify=1">I already have a code</a></p>
                </section>
            <?php else: ?>
                <section class="email-auth email-auth--verify" aria-labelledby="email-auth-verify-heading">
                    <h2 id="email-auth-verify-heading" class="email-auth__headline">Enter the code we emailed you.</h2>

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
                    </form>

                    <div class="email-auth__secondary">
                        <?php if ($pendingEmail !== null): ?>
                            <form method="post" action="/auth/email/request_code.php" class="email-auth__inline-form">
                                <?php csrf_hidden_field(); ?>
                                <input type="hidden" name="email" value="<?= e($pendingEmail) ?>">
                                <button type="submit" class="email-auth__link-btn">Didn't get a code? Resend</button>
                            </form>
                        <?php else: ?>
                            <details class="email-auth__resend-details">
                                <summary class="email-auth__resend-summary">Didn't get a code? Resend</summary>
                                <div class="email-auth__resend-panel">
                                    <form method="post" action="/auth/email/request_code.php" class="note-form note-form--compact">
                                        <?php csrf_hidden_field(); ?>
                                        <label class="note-form__label" for="resend_email">Email</label>
                                        <input
                                            type="email"
                                            class="note-form__input"
                                            id="resend_email"
                                            name="email"
                                            autocomplete="username"
                                            required
                                        >
                                        <button type="submit" class="btn btn--ghost">Send code again</button>
                                    </form>
                                </div>
                            </details>
                        <?php endif; ?>

                        <p class="email-auth__footnote">
                            <a href="/auth/email/login.php?reset=1">Use a different email</a>
                        </p>
                    </div>
                </section>
            <?php endif; ?>

            <p class="empty-state"><a href="/login.php">Other sign-in options</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
