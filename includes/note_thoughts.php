<?php
/**
 * includes/note_thoughts.php — Timestamped thoughts belonging to a daily note.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/user_timezone.php';
require_once __DIR__ . '/validation.php';

/** Max UTF-8 characters for one thought body (matches legacy note body limit). */
const NOTE_THOUGHT_BODY_MAX_LENGTH = NOTE_CONTENT_MAX_LENGTH;

/** Checkbox POST: thought_is_private=1 means author-only visibility. */
function parse_thought_is_private_from_post(array $post): bool
{
    return isset($post['thought_is_private']) && (string) $post['thought_is_private'] === '1';
}

/**
 * Thoughts visible to the viewer: authors see all rows; others omit private thoughts.
 *
 * @return array<int, list<array{id:int,note_id:int,body:string,created_at:string,is_private:bool}>>
 */
function note_thoughts_grouped_by_note(PDO $pdo, array $noteIds, int $viewerUserId): array
{
    $noteIds = array_values(array_unique(array_filter(array_map('intval', $noteIds), static fn (int $id): bool => $id > 0)));
    if ($noteIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
    $sql = <<<SQL
        SELECT t.id, t.note_id, t.body, t.is_private, t.created_at
        FROM note_thoughts t
        INNER JOIN notes n ON n.id = t.note_id
        WHERE t.note_id IN ($placeholders)
          AND (t.is_private = 0 OR n.user_id = ?)
        ORDER BY t.note_id ASC, t.created_at ASC, t.id ASC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$noteIds, $viewerUserId]);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nid = (int) $row['note_id'];
        if (!isset($map[$nid])) {
            $map[$nid] = [];
        }
        $map[$nid][] = [
            'id' => (int) $row['id'],
            'note_id' => $nid,
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
            'is_private' => (bool) (int) $row['is_private'],
        ];
    }

    return $map;
}

function note_thought_count_for_note(PDO $pdo, int $noteId): int
{
    if ($noteId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM note_thoughts WHERE note_id = ?');
    $stmt->execute([$noteId]);

    return (int) $stmt->fetchColumn();
}

/** Subtle clock label e.g. "9:42am" in the viewer's time zone (createdAt stored as UTC). */
function note_thought_time_label(string $createdAtUtc, string $viewerTz): string
{
    $dt = user_datetime_immutable_utc($createdAtUtc);
    if ($dt === null) {
        return '';
    }
    try {
        $z = new DateTimeZone(user_timezone_normalize($viewerTz));
    } catch (Throwable $e) {
        return '';
    }

    return strtolower($dt->setTimezone($z)->format('g:ia'));
}
