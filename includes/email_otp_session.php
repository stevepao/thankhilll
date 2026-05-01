<?php
/**
 * includes/email_otp_session.php — Server-side email OTP state (not persisted in DB).
 *
 * OTP plaintext exists only in the outbound email and briefly when hashing; session holds hash + expiry + attempts.
 */
declare(strict_types=1);

require_once __DIR__ . '/session.php';

const EMAIL_OTP_TTL_SECONDS = 600;
const EMAIL_OTP_RESEND_SECONDS = 60;
const EMAIL_OTP_MAX_ATTEMPTS = 5;

function email_otp_clear_pending(): void
{
    unset(
        $_SESSION['pending_email'],
        $_SESSION['otp_hash'],
        $_SESSION['otp_expires_at'],
        $_SESSION['otp_attempts'],
        $_SESSION['otp_last_sent_at']
    );
}

function email_otp_pending_ready(): bool
{
    bootstrap_session();

    $email = $_SESSION['pending_email'] ?? null;
    $hash = $_SESSION['otp_hash'] ?? null;
    $exp = $_SESSION['otp_expires_at'] ?? null;

    if (!is_string($email) || $email === '' || !is_string($hash) || $hash === '') {
        return false;
    }

    if (!is_int($exp) && !(is_string($exp) && ctype_digit($exp))) {
        return false;
    }

    $expTs = (int) $exp;
    if (time() > $expTs) {
        email_otp_clear_pending();

        return false;
    }

    return true;
}

/** Current failed verify count for this OTP (session-bound). */
function email_otp_attempt_count(): int
{
    $n = $_SESSION['otp_attempts'] ?? 0;

    return is_int($n) ? max(0, $n) : max(0, (int) $n);
}
