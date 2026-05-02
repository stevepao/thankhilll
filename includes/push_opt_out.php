<?php
/**
 * includes/push_opt_out.php — Remove stored Web Push endpoints when user disables all toggles.
 *
 * Account deletion clears subscriptions via FK CASCADE on users.id.
 */
declare(strict_types=1);

require_once __DIR__ . '/push_subscription_repository.php';

/**
 * When every push preference is off, delete all device subscriptions so nothing dormant remains.
 */
function push_subscriptions_remove_if_all_prefs_disabled(
    PDO $pdo,
    int $userId,
    bool $pushEnabled,
    bool $pushRemindersEnabled,
    bool $pushCommentRepliesEnabled
): void {
    if ($userId <= 0) {
        return;
    }
    if ($pushEnabled || $pushRemindersEnabled || $pushCommentRepliesEnabled) {
        return;
    }
    push_subscription_delete_all_for_user($pdo, $userId);
}
