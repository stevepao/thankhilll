<?php
/**
 * includes/user_preferences.php — Per-user defaults stored in users.preferences_json.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/** @return array<string, mixed> */
function user_preferences_defaults(): array
{
    return [
        'default_note_visibility' => 'private',
        'last_used_group_ids' => [],
        'daily_reminder_enabled' => false,
        'today_show_shared' => true,
        'notes_default_scope' => 'all',
    ];
}

/**
 * @param array<string, mixed> $stored
 * @return array<string, mixed>
 */
function user_preferences_normalize(array $stored): array
{
    $out = user_preferences_defaults();

    $vis = $stored['default_note_visibility'] ?? null;
    if ($vis === 'private' || $vis === 'last_used_groups') {
        $out['default_note_visibility'] = $vis;
    }

    $ids = $stored['last_used_group_ids'] ?? [];
    if (is_array($ids)) {
        $clean = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $n = (int) $id;
                if ($n > 0) {
                    $clean[$n] = $n;
                }
            }
        }
        $out['last_used_group_ids'] = array_values(array_slice(array_values($clean), 0, 32));
    }

    $out['daily_reminder_enabled'] = !empty($stored['daily_reminder_enabled']);

    $out['today_show_shared'] = array_key_exists('today_show_shared', $stored)
        ? !empty($stored['today_show_shared'])
        : true;

    $scope = $stored['notes_default_scope'] ?? null;
    if ($scope === 'all' || $scope === 'mine') {
        $out['notes_default_scope'] = $scope;
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function user_preferences_load(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT preferences_json FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return user_preferences_defaults();
    }

    $decoded = json_decode($raw, true);

    return user_preferences_normalize(is_array($decoded) ? $decoded : []);
}

/**
 * Persists the full preference array (already normalized).
 *
 * @param array<string, mixed> $prefs
 */
function user_preferences_save(PDO $pdo, int $userId, array $prefs): void
{
    $normalized = user_preferences_normalize($prefs);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode preferences.');
    }

    $upd = $pdo->prepare('UPDATE users SET preferences_json = ? WHERE id = ?');
    $upd->execute([$json, $userId]);
}

/**
 * Merge a patch into stored preferences and save.
 *
 * @param array<string, mixed> $patch
 */
function user_preferences_merge_save(PDO $pdo, int $userId, array $patch): void
{
    $current = user_preferences_load($pdo, $userId);
    user_preferences_save($pdo, $userId, array_merge($current, $patch));
}

/** Drop a group id from last_used_group_ids (e.g. after the group is deleted). */
function user_preferences_strip_last_used_group_id(PDO $pdo, int $userId, int $groupId): void
{
    if ($userId <= 0 || $groupId <= 0) {
        return;
    }

    $prefs = user_preferences_load($pdo, $userId);
    $ids = $prefs['last_used_group_ids'] ?? [];
    if (!is_array($ids)) {
        return;
    }

    $filtered = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0 && $n !== $groupId) {
            $filtered[] = $n;
        }
    }
    $prefs['last_used_group_ids'] = $filtered;
    user_preferences_save($pdo, $userId, $prefs);
}
