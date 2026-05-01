<?php
/**
 * includes/note_thoughts.php — Timestamped thoughts belonging to a daily note.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/validation.php';

/** Max UTF-8 characters for one thought body (matches legacy note body limit). */
const NOTE_THOUGHT_BODY_MAX_LENGTH = NOTE_CONTENT_MAX_LENGTH;

/**
 * @return array<int, list<array{id:int,note_id:int,body:string,created_at:string}>>
 */
function note_thoughts_grouped_by_note(PDO $pdo, array $noteIds): array
{
    $noteIds = array_values(array_unique(array_filter(array_map('intval', $noteIds), static fn (int $id): bool => $id > 0)));
    if ($noteIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
    $sql = <<<SQL
        SELECT id, note_id, body, created_at
        FROM note_thoughts
        WHERE note_id IN ($placeholders)
        ORDER BY note_id ASC, created_at ASC, id ASC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($noteIds);

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

/** Subtle clock label e.g. "9:42am". */
function note_thought_time_label(string $createdAt): string
{
    $ts = strtotime($createdAt);

    return $ts !== false ? strtolower(date('g:ia', $ts)) : '';
}
