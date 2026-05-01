<?php
/**
 * index_probe.php — Step-through of Today/index.php data loading (debugging HTTP 500).
 *
 * 1. Add to .env: INDEX_PROBE_SECRET=a-long-random-string
 * 2. Sign in in the browser, then visit: /index_probe.php?key=that-same-string
 * 3. Read which step fails (PDO/SQL message). Remove INDEX_PROBE_SECRET and delete this file after.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

loadEnv();

$secret = isset($_ENV['INDEX_PROBE_SECRET']) && is_string($_ENV['INDEX_PROBE_SECRET'])
    ? $_ENV['INDEX_PROBE_SECRET']
    : '';
$key = $_GET['key'] ?? null;
if ($secret === '' || !is_string($key) || !hash_equals($secret, $key)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/note_library_card.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_access.php';
require_once __DIR__ . '/includes/note_thoughts.php';
require_once __DIR__ . '/includes/thought_reactions.php';
require_once __DIR__ . '/includes/thought_comments.php';
require_once __DIR__ . '/includes/user_timezone.php';

header('Content-Type: text/plain; charset=UTF-8');

$userId = require_login();
$pdo = db();

$run = static function (string $label, callable $fn): void {
    try {
        $fn();
        echo $label . ": OK\n";
    } catch (Throwable $e) {
        echo $label . ": FAILED\n";
        echo '  ' . $e::class . ': ' . $e->getMessage() . "\n";
        echo '  at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        exit;
    }
};

$userTimezone = '';
$userLocalToday = '';
$prefs = [];

$run('user_timezone_get', function () use ($pdo, $userId, &$userTimezone): void {
    $userTimezone = user_timezone_get($pdo, $userId);
});

$run('user_local_today_ymd', function () use (&$userLocalToday, $userTimezone): void {
    $userLocalToday = user_local_today_ymd($userTimezone);
});

$run('user_preferences_load', function () use ($pdo, $userId, &$prefs): void {
    $prefs = user_preferences_load($pdo, $userId);
});

$run('preselected group membership (last_used_groups)', function () use ($pdo, $userId, $prefs): void {
    if (($prefs['default_note_visibility'] ?? '') !== 'last_used_groups') {
        return;
    }
    foreach ($prefs['last_used_group_ids'] ?? [] as $gidRaw) {
        $gid = (int) $gidRaw;
        if ($gid > 0) {
            user_is_group_member($pdo, $userId, $gid);
        }
    }
});

$run('groups_for_user_with_counts', function () use ($pdo, $userId): void {
    groups_for_user_with_counts($pdo, $userId);
});

$yoursToday = [];
$todayPrimaryId = 0;
$todayThoughts = [];

$run('today primary note query', function () use ($pdo, $userId, $userLocalToday, &$yoursToday, &$todayPrimaryId): void {
    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT n.id, n.entry_date, n.created_at
        FROM notes n
        WHERE n.user_id = ?
          AND n.entry_date = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$userId, $userLocalToday]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $yoursToday = is_array($row) ? [$row] : [];
    $todayPrimaryId = isset($yoursToday[0]['id']) ? (int) $yoursToday[0]['id'] : 0;
});

$run('note_thoughts_grouped_by_note', function () use ($pdo, $userId, $todayPrimaryId, &$todayThoughts): void {
    if ($todayPrimaryId <= 0) {
        return;
    }
    $thoughtMap = note_thoughts_grouped_by_note($pdo, [$todayPrimaryId], $userId);
    $todayThoughts = $thoughtMap[$todayPrimaryId] ?? [];
});

$run('thought_reactions_grouped_by_thought', function () use ($pdo, $userId, $todayThoughts): void {
    thought_reactions_grouped_by_thought($pdo, array_column($todayThoughts, 'id'), $userId);
});

$todayNoteSharedWithGroup = false;

$run('note_is_shared_with_any_group + thought_comments_grouped_by_thought', function () use (
    $pdo,
    $todayPrimaryId,
    $todayThoughts,
    &$todayNoteSharedWithGroup
): void {
    $todayNoteSharedWithGroup = $todayPrimaryId > 0 && note_is_shared_with_any_group($pdo, $todayPrimaryId);
    $thoughtIdsForCommentFetch = [];
    if ($todayNoteSharedWithGroup) {
        foreach ($todayThoughts as $thForComments) {
            if (empty($thForComments['is_private'])) {
                $thoughtIdsForCommentFetch[] = (int) $thForComments['id'];
            }
        }
    }
    thought_comments_grouped_by_thought($pdo, $thoughtIdsForCommentFetch);
});

$run('today primary shared rows (note_groups)', function () use ($pdo, $todayPrimaryId): void {
    if ($todayPrimaryId <= 0) {
        return;
    }
    $sgStmt = $pdo->prepare(
        <<<'SQL'
        SELECT g.id, g.name
        FROM note_groups ng
        INNER JOIN `groups` g ON g.id = ng.group_id
        WHERE ng.note_id = ?
        ORDER BY g.name ASC
        SQL
    );
    $sgStmt->execute([$todayPrimaryId]);
    $sgStmt->fetchAll(PDO::FETCH_ASSOC);
});

$sharedToday = [];
$showSharedOnToday = !empty($prefs['today_show_shared']);

$run('shared today notes query', function () use (
    $pdo,
    $userId,
    $userLocalToday,
    $showSharedOnToday,
    &$sharedToday
): void {
    if (!$showSharedOnToday) {
        return;
    }
    $sharedStmt = $pdo->prepare(
        <<<'SQL'
        SELECT
            n.id,
            n.entry_date,
            n.user_id,
            MAX(COALESCE(u.display_name, '')) AS author_name,
            GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS shared_group_names
        FROM notes n
        LEFT JOIN users u ON u.id = n.user_id
        LEFT JOIN note_groups ng ON ng.note_id = n.id
        LEFT JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
        LEFT JOIN `groups` g ON g.id = ng.group_id AND gm.user_id IS NOT NULL
        WHERE n.user_id <> ?
          AND n.entry_date = ?
          AND EXISTS (
              SELECT 1
              FROM note_groups ngx
              INNER JOIN group_members gmx ON gmx.group_id = ngx.group_id AND gmx.user_id = ?
              WHERE ngx.note_id = n.id
          )
        GROUP BY n.id, n.entry_date, n.user_id
        ORDER BY n.entry_date DESC, n.id DESC
        SQL
    );
    $sharedStmt->execute([$userId, $userId, $userId, $userLocalToday]);
    $sharedToday = $sharedStmt->fetchAll(PDO::FETCH_ASSOC);
});

$run('note_media_grouped_by_note', function () use ($pdo, $yoursToday, $sharedToday): void {
    $todayNoteIds = array_merge(
        array_column($yoursToday, 'id'),
        array_column($sharedToday, 'id')
    );
    note_media_grouped_by_note($pdo, $todayNoteIds);
});

$run('shared thoughts + reactions + comments', function () use ($pdo, $userId, $sharedToday): void {
    if ($sharedToday === []) {
        return;
    }
    $sharedThoughtsByNote = note_thoughts_grouped_by_note($pdo, array_column($sharedToday, 'id'), $userId);
    $ids = [];
    foreach ($sharedThoughtsByNote as $rows) {
        foreach ($rows as $tr) {
            $ids[] = (int) $tr['id'];
        }
    }
    if ($ids !== []) {
        thought_reactions_grouped_by_thought($pdo, $ids, $userId);
        thought_comments_grouped_by_thought($pdo, $ids);
    }
});

$run('user_can_edit_note_today', function () use ($pdo, $userId, $todayPrimaryId): void {
    if ($todayPrimaryId <= 0) {
        return;
    }
    user_can_edit_note_today($pdo, $userId, $todayPrimaryId);
});

echo "\nAll probe steps passed. If index.php still returns 500, the fault is likely after these queries\n";
echo "(output/HTML/include): try notes.php and check PHP error logs for the exact file and line.\n";
