<?php
/**
 * Issue a DB-backed one-time code emailed to the address (cross-device safe).
 *
 * Generic redirect avoids account/email enumeration.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/email_otp_repository.php';
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

$pdo = db();
$latest = email_otp_repo_latest_row($pdo, $email);

if (email_otp_repo_is_throttled($latest)) {
    header('Location: /auth/email/login.php?sent=1');
    exit;
}

$otp = random_int(100000, 999999);
$otpString = (string) $otp;
$hash = password_hash($otpString, PASSWORD_DEFAULT);

email_otp_repo_invalidate_unconsumed($pdo, $email);
email_otp_repo_insert_challenge($pdo, $email, $hash);

$body = "Your sign-in code is: {$otpString}\n\n"
    . 'This code expires in 10 minutes. '
    . "If you didn't request this, you can ignore this email.\n";

send_email($email, 'Your sign-in code', $body);

header('Location: /auth/email/login.php?sent=1');
exit;
