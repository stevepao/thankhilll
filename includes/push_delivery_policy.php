<?php
/**
 * includes/push_delivery_policy.php — Invariants for future send path (no sending here).
 *
 * Do not send a push unless the user has turned push on and at least one subscription row exists.
 * Callers that target reminder/reply channels must also check the relevant pref flags.
 */
declare(strict_types=1);

require_once __DIR__ . '/push_subscription_repository.php';
require_once __DIR__ . '/user_notification_prefs_repository.php';

/**
 * True when global push is enabled and the user has at least one active subscription record.
 */
function push_delivery_possible(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $prefs = user_notification_prefs_get($pdo, $userId);
    if (!$prefs['push_enabled']) {
        return false;
    }

    return push_subscription_list_for_user($pdo, $userId) !== [];
}
