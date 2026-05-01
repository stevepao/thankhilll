<?php
/**
 * includes/account_delete.php — Permanent account deletion (user-owned data + identities).
 */
declare(strict_types=1);

require_once __DIR__ . '/note_media.php';
require_once __DIR__ . '/google_oauth_revoke.php';

/**
 * Google OAuth refresh tokens stored on auth_identities.secret_hash (provider google).
 *
 * @return list<string>
 */
function account_delete_collect_google_refresh_tokens(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT secret_hash FROM auth_identities
        WHERE user_id = ?
          AND provider = 'google'
          AND secret_hash IS NOT NULL
          AND TRIM(secret_hash) <> ''
        SQL
    );
    $stmt->execute([$userId]);
    $seen = [];
    while ($col = $stmt->fetchColumn()) {
        if (!is_string($col)) {
            continue;
        }
        $t = trim($col);
        if ($t !== '' && !isset($seen[$t])) {
            $seen[$t] = true;
        }
    }

    return array_keys($seen);
}

/**
 * Emails to clear from email_login_otps after the user row is gone.
 *
 * @return list<string>
 */
function account_delete_collect_otp_emails(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $seen = [];
    $stmt = $pdo->prepare('SELECT login_email_normalized FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    if (is_string($v)) {
        $t = trim($v);
        if ($t !== '') {
            $seen[strtolower($t)] = $t;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT identifier FROM auth_identities WHERE user_id = ? AND provider = \'email\''
    );
    $stmt->execute([$userId]);
    while ($col = $stmt->fetchColumn()) {
        if (!is_string($col)) {
            continue;
        }
        $t = trim($col);
        if ($t !== '') {
            $seen[strtolower($t)] = $t;
        }
    }

    return array_values($seen);
}

/**
 * Hard-delete the user and all attributable rows. Groups are kept; owners become NULL via FK.
 * Idempotent if the user row is already gone.
 */
function account_delete_user_completely(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $exists->execute([$userId]);
    if (!$exists->fetchColumn()) {
        return true;
    }

    $otpEmails = account_delete_collect_otp_emails($pdo, $userId);
    $googleTokens = account_delete_collect_google_refresh_tokens($pdo, $userId);
    foreach ($googleTokens as $gTok) {
        google_oauth_revoke_token_logged($gTok);
    }
    if ($googleTokens === []) {
        $gStmt = $pdo->prepare(
            'SELECT 1 FROM auth_identities WHERE user_id = ? AND provider = \'google\' LIMIT 1'
        );
        $gStmt->execute([$userId]);
        if ($gStmt->fetchColumn()) {
            error_log(
                'account_delete_user_completely: Google identity present but no refresh token stored; revocation skipped'
            );
        }
    }

    $pathsStmt = $pdo->prepare(
        <<<'SQL'
        SELECT nm.file_path
        FROM note_media nm
        INNER JOIN notes n ON n.id = nm.note_id
        WHERE n.user_id = ?
        SQL
    );
    $pathsStmt->execute([$userId]);
    $paths = [];
    while ($col = $pathsStmt->fetchColumn()) {
        if (is_string($col) && $col !== '') {
            $paths[] = $col;
        }
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM group_invitations WHERE invited_by_user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM group_invite_requests WHERE requester_user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM group_members WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM notes WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('account_delete_user_completely: ' . $e->getMessage());

        return false;
    }

    foreach ($paths as $rel) {
        note_media_delete_relative_report($rel);
    }

    if ($otpEmails !== []) {
        try {
            $otpDel = $pdo->prepare(
                'DELETE FROM email_login_otps WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))'
            );
            foreach ($otpEmails as $em) {
                $otpDel->execute([$em]);
            }
        } catch (Throwable $e) {
            error_log('account_delete_user_completely OTP cleanup: ' . $e->getMessage());
        }
    }

    return true;
}
