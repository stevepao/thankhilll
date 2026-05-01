<?php
/**
 * includes/note_access.php — Whether the current user may read a note (author or shared group).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/user_timezone.php';

function user_can_view_note(PDO $pdo, int $userId, int $noteId): bool
{
    if ($noteId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT 1
        FROM notes n
        WHERE n.id = ?
          AND (
              n.user_id = ?
              OR EXISTS (
                  SELECT 1
                  FROM note_groups ng
                  INNER JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
                  WHERE ng.note_id = n.id
              )
          )
        LIMIT 1
        SQL
    );
    $stmt->execute([$noteId, $userId, $userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * True when the user may view this thought via parent note access and privacy rule.
 * Private thoughts are author-only.
 */
function user_can_view_thought(PDO $pdo, int $userId, int $thoughtId): bool
{
    if ($thoughtId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT 1
        FROM note_thoughts t
        INNER JOIN notes n ON n.id = t.note_id
        WHERE t.id = ?
          AND (
              n.user_id = ?
              OR EXISTS (
                  SELECT 1
                  FROM note_groups ng
                  INNER JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
                  WHERE ng.note_id = n.id
              )
          )
          AND (t.is_private = 0 OR n.user_id = ?)
        LIMIT 1
        SQL
    );
    $stmt->execute([$thoughtId, $userId, $userId, $userId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * True when the user owns the note and its calendar entry_date is today
 * (sharing + note-level photos editable).
 */
function user_can_edit_note_today(PDO $pdo, int $userId, int $noteId): bool
{
    if ($noteId <= 0) {
        return false;
    }

    $todayLocal = user_local_today_ymd(user_timezone_get($pdo, $userId));

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT 1
        FROM notes n
        WHERE n.id = ?
          AND n.user_id = ?
          AND n.entry_date = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$noteId, $userId, $todayLocal]);

    return (bool) $stmt->fetchColumn();
}

/**
 * True when the user owns the note and its calendar day is still today
 * (edit/delete thought body, toggle private — no changes after the entry day).
 */
function user_can_edit_thought_today(PDO $pdo, int $userId, int $thoughtId): bool
{
    if ($thoughtId <= 0) {
        return false;
    }

    $todayLocal = user_local_today_ymd(user_timezone_get($pdo, $userId));

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT 1
        FROM note_thoughts t
        INNER JOIN notes n ON n.id = t.note_id
        WHERE t.id = ?
          AND n.user_id = ?
          AND n.entry_date = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$thoughtId, $userId, $todayLocal]);

    return (bool) $stmt->fetchColumn();
}
