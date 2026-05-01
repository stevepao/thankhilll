<?php
/**
 * Verify emailed OTP, then resolve/create user + email identity and log in (same session path as Google).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/email_otp_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/email/login.php');
    exit;
}

csrf_verify_post_or_abort();

if (!email_otp_pending_ready()) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$codeRaw = $_POST['code'] ?? null;
if (!is_string($codeRaw)) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$code = trim($codeRaw);
if (!email_auth_otp_format_ok($code)) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$storedHash = $_SESSION['otp_hash'] ?? '';
if (!is_string($storedHash) || $storedHash === '' || !password_verify($code, $storedHash)) {
    $_SESSION['otp_attempts'] = email_otp_attempt_count() + 1;
    if (email_otp_attempt_count() >= EMAIL_OTP_MAX_ATTEMPTS) {
        email_otp_clear_pending();
    }
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$pendingEmail = email_auth_normalize($_SESSION['pending_email'] ?? null);
if ($pendingEmail === null) {
    email_otp_clear_pending();
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$pdo = db();
$find = $pdo->prepare(
    'SELECT user_id FROM auth_identities WHERE provider = ? AND identifier = ? LIMIT 1'
);
$find->execute(['email', $pendingEmail]);
$row = $find->fetch();

$userId = 0;

if (is_array($row) && isset($row['user_id'])) {
    $userId = (int) $row['user_id'];
    $touch = $pdo->prepare(
        'UPDATE auth_identities SET secret_hash = NULL, last_used_at = CURRENT_TIMESTAMP WHERE provider = ? AND identifier = ?'
    );
    $touch->execute(['email', $pendingEmail]);
} else {
    $pdo->beginTransaction();

    try {
        $displayName = email_auth_display_name_from_email($pendingEmail);
        $insUser = $pdo->prepare(
            'INSERT INTO users (display_name, preferences_json) VALUES (?, NULL)'
        );
        $insUser->execute([$displayName]);
        $userId = (int) $pdo->lastInsertId();

        $insId = $pdo->prepare(
            'INSERT INTO auth_identities (user_id, provider, identifier, secret_hash, last_used_at)
             VALUES (?, ?, ?, NULL, CURRENT_TIMESTAMP)'
        );
        $insId->execute([$userId, 'email', $pendingEmail]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Email OTP signup/login failed: ' . $e->getMessage());
        email_otp_clear_pending();
        header('Location: /auth/email/login.php?err=1');
        exit;
    }
}

email_otp_clear_pending();
session_commit_login($userId);

header('Location: /index.php');
exit;
