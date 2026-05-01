<?php
/**
 * Issue a one-time sign-in code emailed to the address (session stores hash only).
 *
 * Generic redirects avoid revealing whether an account exists.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/email_otp_session.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/email/login.php');
    exit;
}

csrf_verify_post_or_abort();

$email = email_auth_normalize($_POST['email'] ?? null);
if ($email === null) {
    header('Location: /auth/email/login.php?sent=1');
    exit;
}

// Switching to a different address mid-flow drops any prior OTP state.
if (email_otp_pending_ready()) {
    $pendingNorm = email_auth_normalize($_SESSION['pending_email'] ?? '');
    if ($pendingNorm !== null && $pendingNorm !== $email) {
        email_otp_clear_pending();
    }
}

$lastSent = (int) ($_SESSION['otp_last_sent_at'] ?? 0);
if ($lastSent > 0 && time() - $lastSent < EMAIL_OTP_RESEND_SECONDS) {
    header('Location: /auth/email/login.php?sent=1');
    exit;
}

$otp = random_int(100000, 999999);
$otpString = (string) $otp;

$_SESSION['pending_email'] = $email;
$_SESSION['otp_hash'] = password_hash($otpString, PASSWORD_DEFAULT);
$_SESSION['otp_expires_at'] = time() + EMAIL_OTP_TTL_SECONDS;
$_SESSION['otp_attempts'] = 0;
$_SESSION['otp_last_sent_at'] = time();

$body = "Your sign-in code is: {$otpString}\n\n"
    . 'This code expires in 10 minutes. '
    . "If you didn't request this, you can ignore this email.\n";

send_email($email, 'Your sign-in code', $body);

header('Location: /auth/email/login.php?sent=1');
exit;
