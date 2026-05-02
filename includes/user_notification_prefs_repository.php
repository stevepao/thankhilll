<?php
/**
 * includes/user_notification_prefs_repository.php — Per-user push notification toggles.
 *
 * No row means all flags false until explicitly saved.
 */
declare(strict_types=1);

require_once __DIR__ . '/push_opt_out.php';
require_once dirname(__DIR__) . '/db.php';

/**
 * @return array{
 *   push_enabled: bool,
 *   push_reminders_enabled: bool,
 *   push_comment_replies_enabled: bool,
 *   created_at: string|null,
 *   updated_at: string|null
 * }
 */
function user_notification_prefs_get(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [
            'push_enabled' => false,
            'push_reminders_enabled' => false,
            'push_comment_replies_enabled' => false,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT push_enabled, push_reminders_enabled, push_comment_replies_enabled, created_at, updated_at
        FROM user_notification_prefs
        WHERE user_id = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return [
            'push_enabled' => false,
            'push_reminders_enabled' => false,
            'push_comment_replies_enabled' => false,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    return [
        'push_enabled' => (int) ($row['push_enabled'] ?? 0) === 1,
        'push_reminders_enabled' => (int) ($row['push_reminders_enabled'] ?? 0) === 1,
        'push_comment_replies_enabled' => (int) ($row['push_comment_replies_enabled'] ?? 0) === 1,
        'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : null,
        'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
    ];
}

/**
 * Insert or replace all three booleans for this user.
 */
function user_notification_prefs_save(
    PDO $pdo,
    int $userId,
    bool $pushEnabled,
    bool $pushRemindersEnabled,
    bool $pushCommentRepliesEnabled
): void {
    if ($userId <= 0) {
        throw new InvalidArgumentException('user_notification_prefs_save: invalid user_id');
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        INSERT INTO user_notification_prefs (
            user_id, push_enabled, push_reminders_enabled, push_comment_replies_enabled
        ) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            push_enabled = VALUES(push_enabled),
            push_reminders_enabled = VALUES(push_reminders_enabled),
            push_comment_replies_enabled = VALUES(push_comment_replies_enabled),
            updated_at = CURRENT_TIMESTAMP
        SQL
    );

    $stmt->execute([
        $userId,
        $pushEnabled ? 1 : 0,
        $pushRemindersEnabled ? 1 : 0,
        $pushCommentRepliesEnabled ? 1 : 0,
    ]);

    push_subscriptions_remove_if_all_prefs_disabled(
        $pdo,
        $userId,
        $pushEnabled,
        $pushRemindersEnabled,
        $pushCommentRepliesEnabled
    );

    try {
        $sync = $pdo->prepare('UPDATE users SET daily_reminder_enabled = ? WHERE id = ?');
        $sync->execute([$pushRemindersEnabled ? 1 : 0, $userId]);
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_column($e)) {
            throw $e;
        }
    }
}

/**
 * Delete prefs row (e.g. redundant after CASCADE on user delete; safe no-op if missing).
 */
function user_notification_prefs_delete_for_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM user_notification_prefs WHERE user_id = ?');

    return $stmt->execute([$userId]) && $stmt->rowCount() > 0;
}
