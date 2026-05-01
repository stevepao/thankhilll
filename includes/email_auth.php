<?php
/**
 * includes/email_auth.php — Shared normalization and PIN rules for provider `email`.
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

/** PIN length rules (6–12 characters; trimmed byte length). */
function email_auth_pin_length_ok(string $pin): bool
{
    $len = strlen($pin);

    return $len >= 6 && $len <= 12;
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
