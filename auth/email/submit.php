<?php
/**
 * Processes email + PIN login POST (provider `email`).
 *
 * Uses the same session commit path as Google after verifying credentials (no SMTP here).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/validation.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/email/login.php');
    exit;
}

csrf_verify_post_or_abort();

$email = email_auth_normalize($_POST['email'] ?? null);
$pinResult = validate_required_string($_POST['pin'] ?? null, 12);

if ($email === null || !$pinResult['ok'] || !email_auth_pin_length_ok($pinResult['value'])) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT user_id, secret_hash FROM auth_identities WHERE provider = ? AND identifier = ? LIMIT 1'
);
$stmt->execute(['email', $email]);
$row = $stmt->fetch();

$authenticated = false;
$userId = 0;

if (is_array($row) && isset($row['user_id'], $row['secret_hash']) && is_string($row['secret_hash'])) {
    $hash = $row['secret_hash'];
    if ($hash !== '' && password_verify($pinResult['value'], $hash)) {
        $authenticated = true;
        $userId = (int) $row['user_id'];
    }
}

if (!$authenticated) {
    header('Location: /auth/email/login.php?err=1');
    exit;
}

$touch = $pdo->prepare(
    'UPDATE auth_identities SET last_used_at = CURRENT_TIMESTAMP WHERE provider = ? AND identifier = ?'
);
$touch->execute(['email', $email]);

session_commit_login($userId);

header('Location: /index.php');
exit;
