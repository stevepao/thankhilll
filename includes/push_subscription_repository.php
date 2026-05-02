<?php
/**
 * includes/push_subscription_repository.php — Web Push subscription rows (per device/browser).
 */
declare(strict_types=1);

/**
 * Stable unique key for an endpoint URL (MySQL unique index on full TEXT URLs is awkward).
 */
function push_subscription_endpoint_hash(string $endpointUrl): string
{
    return hash('sha256', $endpointUrl);
}

/**
 * @return array<string, mixed>|null Row including id, user_id, endpoint_hash, endpoint_url, p256dh, auth, etc.
 */
function push_subscription_find_by_endpoint_hash(PDO $pdo, string $endpointHash): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM push_subscriptions WHERE endpoint_hash = ? LIMIT 1'
    );
    $stmt->execute([$endpointHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function push_subscription_list_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM push_subscriptions WHERE user_id = ? ORDER BY created_at ASC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Insert or update subscription for this endpoint (endpoint is globally unique).
 *
 * @return int Subscription id
 */
function push_subscription_upsert(
    PDO $pdo,
    int $userId,
    string $endpointUrl,
    string $p256dh,
    string $auth,
    ?int $expirationTimeMs = null,
    ?string $userAgent = null
): int {
    if ($userId <= 0) {
        throw new InvalidArgumentException('push_subscription_upsert: invalid user_id');
    }

    $hash = push_subscription_endpoint_hash($endpointUrl);

    $stmt = $pdo->prepare(
        <<<'SQL'
        INSERT INTO push_subscriptions (
            user_id, endpoint_hash, endpoint_url, p256dh, auth,
            expiration_time_ms, user_agent, last_used_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, NULL
        )
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            endpoint_url = VALUES(endpoint_url),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            expiration_time_ms = VALUES(expiration_time_ms),
            user_agent = VALUES(user_agent),
            updated_at = CURRENT_TIMESTAMP
        SQL
    );

    $stmt->execute([
        $userId,
        $hash,
        $endpointUrl,
        $p256dh,
        $auth,
        $expirationTimeMs,
        $userAgent,
    ]);

    $id = (int) $pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }

    $row = push_subscription_find_by_endpoint_hash($pdo, $hash);
    if ($row !== null && isset($row['id'])) {
        return (int) $row['id'];
    }

    throw new RuntimeException('push_subscription_upsert: could not resolve id');
}

function push_subscription_delete_by_id(PDO $pdo, int $userId, int $subscriptionId): bool
{
    if ($userId <= 0 || $subscriptionId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?'
    );

    return $stmt->execute([$subscriptionId, $userId]) && $stmt->rowCount() > 0;
}

/**
 * Remove one subscription by opaque endpoint URL (scoped to user). Endpoint must match stored URL exactly.
 */
function push_subscription_delete_by_endpoint_for_user(PDO $pdo, int $userId, string $endpointUrl): void
{
    if ($userId <= 0 || $endpointUrl === '') {
        return;
    }

    $hash = push_subscription_endpoint_hash($endpointUrl);
    $stmt = $pdo->prepare(
        'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?'
    );
    $stmt->execute([$userId, $hash]);
}

function push_subscription_delete_all_for_user(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);

    return $stmt->rowCount();
}

function push_subscription_touch_last_used(PDO $pdo, int $userId, int $subscriptionId): bool
{
    if ($userId <= 0 || $subscriptionId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        UPDATE push_subscriptions
        SET last_used_at = NOW(), updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND user_id = ?
        SQL
    );

    return $stmt->execute([$subscriptionId, $userId]) && $stmt->rowCount() > 0;
}
