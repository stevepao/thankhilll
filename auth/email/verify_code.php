<?php
/**
 * Verify DB-backed OTP, then resolve/create user + email identity and log in.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/email_otp_repository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/email/login.php');
    exit;
}

csrf_verify_post_or_abort();

$email = email_auth_normalize($_POST['email'] ?? null);
if ($email === null) {
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

$pdo = db();
$row = email_otp_repo_find_active_challenge($pdo, $email);

if ($row === null || empty($row['otp_hash']) || !is_string($row['otp_hash'])) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$challengeId = (int) $row['id'];
$storedHash = $row['otp_hash'];

if (!password_verify($code, $storedHash)) {
    email_otp_repo_increment_attempts($pdo, $challengeId);
    header('Location: /auth/email/login.php?err=1');
    exit;
}

email_otp_repo_mark_consumed($pdo, $challengeId);

$find = $pdo->prepare(
    'SELECT user_id FROM auth_identities WHERE provider = ? AND identifier = ? LIMIT 1'
);
$find->execute(['email', $email]);
$identityRow = $find->fetch();

$userId = 0;

if (is_array($identityRow) && isset($identityRow['user_id'])) {
    $userId = (int) $identityRow['user_id'];
    $touch = $pdo->prepare(
        'UPDATE auth_identities SET secret_hash = NULL, last_used_at = CURRENT_TIMESTAMP WHERE provider = ? AND identifier = ?'
    );
    $touch->execute(['email', $email]);
} else {
    $pdo->beginTransaction();

    try {
        $displayName = email_auth_display_name_from_email($email);
        $insUser = $pdo->prepare(
            'INSERT INTO users (display_name, preferences_json) VALUES (?, NULL)'
        );
        $insUser->execute([$displayName]);
        $userId = (int) $pdo->lastInsertId();

        $insId = $pdo->prepare(
            'INSERT INTO auth_identities (user_id, provider, identifier, secret_hash, last_used_at)
             VALUES (?, ?, ?, NULL, CURRENT_TIMESTAMP)'
        );
        $insId->execute([$userId, 'email', $email]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Email OTP verify signup failed: ' . $e->getMessage());
        header('Location: /auth/email/login.php?err=1');
        exit;
    }
}

session_commit_login($userId);

header('Location: /index.php');
exit;
