<?php
/**
 * includes/email_auth.php — Shared normalization for provider `email`.
 *
 * Keeps SMTP and Google OIDC separate from identifier shaping used in auth_identities.
 */
declare(strict_types=1);

/**
 * Normalize email for provider identifier (trim + lowercase). Returns null if format is invalid.
 */
function email_auth_normalize(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }

    $email = strtolower(trim($raw));
    if ($email === '') {
        return null;
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    return $email;
}

/** True if string is exactly six ASCII digits (OTP entry). */
function email_auth_otp_format_ok(string $code): bool
{
    return preg_match('/^[0-9]{6}$/', $code) === 1;
}

/**
 * Derive a display_name from an email local-part (max 120 chars for users.display_name).
 */
function email_auth_display_name_from_email(string $email): string
{
    $local = explode('@', $email, 2)[0];
    if (function_exists('mb_substr')) {
        return mb_substr($local, 0, 120, 'UTF-8');
    }

    return substr($local, 0, 120);
}

/** Session-only UX for OTP step 2 prefill; verification never depends on this key. */
const EMAIL_OTP_PENDING_EMAIL_SESSION_KEY = 'email_otp_pending_email';

function email_otp_session_set_pending_email(string $normalizedEmail): void
{
    require_once __DIR__ . '/session.php';
    bootstrap_session();
    $_SESSION[EMAIL_OTP_PENDING_EMAIL_SESSION_KEY] = $normalizedEmail;
}

function email_otp_session_get_pending_email(): ?string
{
    require_once __DIR__ . '/session.php';
    bootstrap_session();
    $raw = $_SESSION[EMAIL_OTP_PENDING_EMAIL_SESSION_KEY] ?? null;

    return is_string($raw) ? email_auth_normalize($raw) : null;
}

function email_otp_session_clear_pending_email(): void
{
    require_once __DIR__ . '/session.php';
    bootstrap_session();
    unset($_SESSION[EMAIL_OTP_PENDING_EMAIL_SESSION_KEY]);
}
