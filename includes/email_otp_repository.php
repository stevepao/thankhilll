<?php
/**
 * includes/email_otp_repository.php — DB access for email OTP challenges (cross-device).
 *
 * Plain OTP exists only in outbound email and at verify time in POST; table holds hashes only.
 */
declare(strict_types=1);

require_once __DIR__ . '/user_timezone.php';

const EMAIL_OTP_VALID_SECONDS = 600;
const EMAIL_OTP_RESEND_SECONDS = 60;
/** Failed verify tries allowed before row is no longer accepted (then SELECT skips it). */
const EMAIL_OTP_MAX_VERIFY_ATTEMPTS = 5;

/**
 * Most recently created row for an email (used for resend throttle).
 *
 * @return array{id: int|string, last_sent_at: string}|null
 */
function email_otp_repo_latest_row(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, last_sent_at FROM email_login_otps WHERE email = ? ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function email_otp_repo_is_throttled(?array $latestRow): bool
{
    if ($latestRow === null || empty($latestRow['last_sent_at'])) {
        return false;
    }

    $sent = user_datetime_immutable_utc((string) $latestRow['last_sent_at']);
    if ($sent === null) {
        return false;
    }

    return (time() - $sent->getTimestamp()) < EMAIL_OTP_RESEND_SECONDS;
}

/** Marks prior unconsumed codes for this email invalid so only the newest challenge works. */
function email_otp_repo_invalidate_unconsumed(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare(
        'UPDATE email_login_otps SET consumed_at = NOW() WHERE email = ? AND consumed_at IS NULL'
    );
    $stmt->execute([$email]);
}

function email_otp_repo_insert_challenge(PDO $pdo, string $email, string $otpHash): void
{
    // Use MySQL NOW() so expiry matches verification checks (avoids PHP vs DB clock skew).
    $stmt = $pdo->prepare(
        'INSERT INTO email_login_otps (email, otp_hash, expires_at, consumed_at, attempts, last_sent_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL, 0, NOW())'
    );
    $stmt->execute([$email, $otpHash, EMAIL_OTP_VALID_SECONDS]);
}

/**
 * Latest usable OTP row for verification.
 *
 * @return array{id: int|string, otp_hash: string, attempts: int|string}|null
 */
function email_otp_repo_find_active_challenge(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, otp_hash, attempts FROM email_login_otps
         WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW() AND attempts < ?
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$email, EMAIL_OTP_MAX_VERIFY_ATTEMPTS]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function email_otp_repo_increment_attempts(PDO $pdo, int $challengeId): void
{
    $stmt = $pdo->prepare('UPDATE email_login_otps SET attempts = attempts + 1 WHERE id = ?');
    $stmt->execute([$challengeId]);
}

function email_otp_repo_mark_consumed(PDO $pdo, int $challengeId): void
{
    $stmt = $pdo->prepare('UPDATE email_login_otps SET consumed_at = NOW() WHERE id = ?');
    $stmt->execute([$challengeId]);
}
