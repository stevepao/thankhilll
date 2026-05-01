<?php
/**
 * includes/thought_comments.php — Flat comments on shared, non-private thoughts.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/user_timezone.php';
require_once __DIR__ . '/validation.php';

const THOUGHT_COMMENT_MAX_LENGTH = 280;

function note_is_shared_with_any_group(PDO $pdo, int $noteId): bool
{
    if ($noteId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM note_groups WHERE note_id = ? LIMIT 1');
    $stmt->execute([$noteId]);

    return (bool) $stmt->fetchColumn();
}

/** Same calendar day as now in viewer TZ, or within 24 hours after thought creation (UTC elapsed). */
function thought_comment_post_window_open(string $thoughtCreatedAtUtc, string $viewerTz): bool
{
    $thought = user_datetime_immutable_utc($thoughtCreatedAtUtc);
    if ($thought === null) {
        return false;
    }

    try {
        $z = new DateTimeZone(user_timezone_normalize($viewerTz));
    } catch (Throwable $e) {
        return false;
    }

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $sameCalendarDay = $thought->setTimezone($z)->format('Y-m-d') === $nowUtc->setTimezone($z)->format('Y-m-d');
    $within24h = $nowUtc->getTimestamp() <= $thought->getTimestamp() + 86400;

    return $sameCalendarDay || $within24h;
}

/** Delete allowed only on the viewer's current local calendar day when the comment was created. */
function thought_comment_delete_window_open(string $commentCreatedAtUtc, string $viewerTz): bool
{
    $comment = user_datetime_immutable_utc($commentCreatedAtUtc);
    if ($comment === null) {
        return false;
    }

    try {
        $z = new DateTimeZone(user_timezone_normalize($viewerTz));
    } catch (Throwable $e) {
        return false;
    }

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $comment->setTimezone($z)->format('Y-m-d') === $nowUtc->setTimezone($z)->format('Y-m-d');
}

/** @return array{ok:true,value:string}|array{ok:false,error:string} */
function thought_comment_validate_body(mixed $raw): array
{
    if (!is_string($raw)) {
        return ['ok' => false, 'error' => 'Invalid input.'];
    }

    $trimmed = trim($raw);
    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'Comment can’t be empty.'];
    }

    if (validation_utf8_length($trimmed) > THOUGHT_COMMENT_MAX_LENGTH) {
        return ['ok' => false, 'error' => 'Comment is too long.'];
    }

    return ['ok' => true, 'value' => $trimmed];
}

/**
 * Allowed redirect targets after create/delete (same-origin paths only).
 */
function thought_comment_redirect_target(mixed $raw): string
{
    $path = is_string($raw) ? trim($raw) : '';
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '/notes.php';
    }

    if (preg_match('#^/index\.php(?:\?[^\s#]*)?$#', $path) === 1) {
        return $path;
    }

    if (preg_match('#^/note\.php\?id=\d+(?:&[^\s#]*)?$#', $path) === 1) {
        return $path;
    }

    return '/notes.php';
}

function thought_comment_time_label(string $createdAtUtc, string $viewerTz): string
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

    $local = $dt->setTimezone($z);
    $nowLocal = (new DateTimeImmutable('now', $z));

    if ($local->format('Y-m-d') === $nowLocal->format('Y-m-d')) {
        return strtolower($local->format('g:ia'));
    }

    return strtolower($local->format('M j, g:ia'));
}

/**
 * @param list<int> $thoughtIds
 * @return array<int, list<array{id:int,thought_id:int,user_id:int,body:string,created_at:string,display_name:string}>>
 */
function thought_comments_grouped_by_thought(PDO $pdo, array $thoughtIds): array
{
    $thoughtIds = array_values(array_unique(array_filter(array_map('intval', $thoughtIds), static fn (int $id): bool => $id > 0)));
    if ($thoughtIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($thoughtIds), '?'));
    $sql = <<<SQL
        SELECT c.id,
               c.thought_id,
               c.user_id,
               c.body,
               c.created_at,
               u.display_name AS display_name
        FROM thought_comments c
        INNER JOIN users u ON u.id = c.user_id
        WHERE c.thought_id IN ($placeholders)
        ORDER BY c.thought_id ASC, c.created_at ASC, c.id ASC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($thoughtIds);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int) $row['thought_id'];
        if (!isset($out[$tid])) {
            $out[$tid] = [];
        }
        $name = trim((string) $row['display_name']);
        if ($name === '') {
            $name = 'Someone';
        }
        $out[$tid][] = [
            'id' => (int) $row['id'],
            'thought_id' => $tid,
            'user_id' => (int) $row['user_id'],
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
            'display_name' => $name,
        ];
    }

    return $out;
}

/**
 * @return array{thought_id:int,note_id:int,is_private:bool,thought_created_at:string}|null
 */
function thought_comment_row_meta(PDO $pdo, int $thoughtId): ?array
{
    if ($thoughtId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT t.id AS thought_id,
               t.note_id,
               t.is_private,
               t.created_at AS thought_created_at
        FROM note_thoughts t
        WHERE t.id = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$thoughtId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'thought_id' => (int) $row['thought_id'],
        'note_id' => (int) $row['note_id'],
        'is_private' => ((int) $row['is_private']) === 1,
        'thought_created_at' => (string) $row['thought_created_at'],
    ];
}

function thought_comment_redirect_with_param(string $baseUrl, string $param): string
{
    $sep = str_contains($baseUrl, '?') ? '&' : '?';

    return $baseUrl . $sep . $param;
}
