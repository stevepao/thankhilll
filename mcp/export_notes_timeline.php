<?php
/**
 * MCP tool export_notes_timeline — AI-friendly JSON for visible notes in a date range.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';
require_once __DIR__ . '/media_signing.php';

const TH_MCP_EXPORT_MAX_RANGE_DAYS = 31;
const TH_MCP_EXPORT_MEDIA_TTL_SECONDS = 600;

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_export_notes_timeline_run(PDO $pdo, int $viewerUserId, array $arguments): array
{
    $fromRaw = $arguments['from'] ?? null;
    $toRaw = $arguments['to'] ?? null;
    if (!is_string($fromRaw) || !is_string($toRaw)) {
        return [
            'text' => json_encode(['error' => 'Missing or invalid from/to; both must be YYYY-MM-DD strings.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_error' => true,
        ];
    }
    $fromRaw = trim($fromRaw);
    $toRaw = trim($toRaw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
        return [
            'text' => json_encode(['error' => 'from and to must be YYYY-MM-DD.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_error' => true,
        ];
    }

    try {
        $fromDay = new DateTimeImmutable($fromRaw . ' 00:00:00', new DateTimeZone('UTC'));
        $toDay = new DateTimeImmutable($toRaw . ' 00:00:00', new DateTimeZone('UTC'));
    } catch (Throwable) {
        return [
            'text' => json_encode(['error' => 'Invalid from/to calendar dates.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_error' => true,
        ];
    }

    if ($fromDay > $toDay) {
        return [
            'text' => json_encode(['error' => 'from must be on or before to.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_error' => true,
        ];
    }

    $inclusiveDays = (int) $fromDay->diff($toDay)->days + 1;
    if ($inclusiveDays > TH_MCP_EXPORT_MAX_RANGE_DAYS) {
        return [
            'text' => json_encode(
                ['error' => 'Date range exceeds ' . TH_MCP_EXPORT_MAX_RANGE_DAYS . ' days (inclusive).'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'is_error' => true,
        ];
    }

    $includeUrls = true;
    if (array_key_exists('include_view_urls', $arguments)) {
        $iv = $arguments['include_view_urls'];
        if (is_bool($iv)) {
            $includeUrls = $iv;
        } elseif ($iv === 0 || $iv === 1) {
            $includeUrls = (bool) $iv;
        } elseif (is_string($iv)) {
            $includeUrls = !in_array(strtolower($iv), ['false', '0', 'no', ''], true);
        }
    }

    if ($includeUrls && mcp_media_signing_secret() === '') {
        return [
            'text' => json_encode(
                ['error' => 'include_view_urls requires MCP_MEDIA_SIGNING_KEY to be set on the server.'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'is_error' => true,
        ];
    }

    $tzName = user_timezone_get($pdo, $viewerUserId);

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT n.id, n.user_id, n.entry_date, n.visibility, n.created_at, n.updated_at
        FROM notes n
        WHERE n.entry_date BETWEEN ? AND ?
          AND n.user_id IS NOT NULL
          AND (
              n.user_id = ?
              OR EXISTS (
                  SELECT 1
                  FROM note_groups ng
                  INNER JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
                  WHERE ng.note_id = n.id
              )
          )
        ORDER BY n.entry_date ASC, n.id ASC
        SQL
    );
    $stmt->execute([$fromRaw, $toRaw, $viewerUserId, $viewerUserId]);
    $noteRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $noteIds = [];
    foreach ($noteRows as $nr) {
        if (is_array($nr) && isset($nr['id'])) {
            $noteIds[] = (int) $nr['id'];
        }
    }

    /** @var array<int, list<int>> $groupsByNote */
    $groupsByNote = [];
    if ($noteIds !== []) {
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $gStmt = $pdo->prepare(
            "SELECT note_id, group_id FROM note_groups WHERE note_id IN ($placeholders)"
        );
        $gStmt->execute($noteIds);
        while ($g = $gStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($g)) {
                continue;
            }
            $nid = (int) ($g['note_id'] ?? 0);
            $gid = (int) ($g['group_id'] ?? 0);
            if ($nid > 0 && $gid > 0) {
                $groupsByNote[$nid] ??= [];
                $groupsByNote[$nid][] = $gid;
            }
        }
    }

    /** @var array<int, array<string,mixed>> $thoughtById */
    $thoughtById = [];
    /** @var list<int> $thoughtIds */
    $thoughtIds = [];

    if ($noteIds !== []) {
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $bind = $noteIds;
        $bind[] = $viewerUserId;
        $tStmt = $pdo->prepare(
            <<<SQL
            SELECT t.id, t.note_id, t.body, t.is_private, t.created_at
            FROM note_thoughts t
            INNER JOIN notes n ON n.id = t.note_id
            WHERE t.note_id IN ($placeholders)
              AND (t.is_private = 0 OR n.user_id = ?)
            ORDER BY t.created_at ASC
            SQL
        );
        $tStmt->execute($bind);
        while ($t = $tStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($t)) {
                continue;
            }
            $tid = (int) ($t['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $thoughtById[$tid] = $t;
            $thoughtIds[] = $tid;
        }
    }

    /** @var array<int, array<string,mixed>> $commentById */
    $commentById = [];
    if ($thoughtIds !== []) {
        $placeholders = implode(',', array_fill(0, count($thoughtIds), '?'));
        $cStmt = $pdo->prepare(
            "SELECT id, thought_id, user_id, body, created_at FROM thought_comments WHERE thought_id IN ($placeholders) ORDER BY created_at ASC"
        );
        $cStmt->execute($thoughtIds);
        while ($c = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($c)) {
                continue;
            }
            $cid = (int) ($c['id'] ?? 0);
            if ($cid > 0) {
                $commentById[$cid] = $c;
            }
        }
    }

    /** @var array<int, array<string,mixed>> $reactionById */
    $reactionById = [];
    if ($thoughtIds !== []) {
        $placeholders = implode(',', array_fill(0, count($thoughtIds), '?'));
        $rStmt = $pdo->prepare(
            "SELECT id, thought_id, user_id, emoji, created_at FROM thought_reactions WHERE thought_id IN ($placeholders) ORDER BY created_at ASC"
        );
        $rStmt->execute($thoughtIds);
        while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($r)) {
                continue;
            }
            $rid = (int) ($r['id'] ?? 0);
            if ($rid > 0) {
                $reactionById[$rid] = $r;
            }
        }
    }

    /** @var array<int, array<string,mixed>> $mediaById */
    $mediaById = [];
    if ($noteIds !== []) {
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $mStmt = $pdo->prepare(
            "SELECT id, note_id, file_path, width, height, created_at FROM note_media WHERE note_id IN ($placeholders) ORDER BY created_at ASC"
        );
        $mStmt->execute($noteIds);
        while ($m = $mStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($m)) {
                continue;
            }
            $mid = (int) ($m['id'] ?? 0);
            if ($mid > 0) {
                $mediaById[$mid] = $m;
            }
        }
    }

    $peopleIds = [$viewerUserId => true];
    foreach ($noteRows as $nr) {
        if (is_array($nr) && isset($nr['user_id'])) {
            $peopleIds[(int) $nr['user_id']] = true;
        }
    }
    foreach ($commentById as $c) {
        $peopleIds[(int) ($c['user_id'] ?? 0)] = true;
    }
    foreach ($reactionById as $r) {
        $peopleIds[(int) ($r['user_id'] ?? 0)] = true;
    }
    unset($peopleIds[0]);

    $peopleMap = [];
    if ($peopleIds !== []) {
        $ids = array_keys($peopleIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $uStmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id IN ($ph)");
        $uStmt->execute($ids);
        while ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($u)) {
                continue;
            }
            $uid = (int) ($u['id'] ?? 0);
            if ($uid > 0) {
                $dn = $u['display_name'] ?? null;
                $peopleMap[(string) $uid] = [
                    'display_name' => is_string($dn) && $dn !== '' ? $dn : null,
                ];
            }
        }
    }

    $objectsNotes = [];
    foreach ($noteRows as $nr) {
        if (!is_array($nr)) {
            continue;
        }
        $nid = (int) ($nr['id'] ?? 0);
        if ($nid <= 0) {
            continue;
        }
        $authorId = (int) ($nr['user_id'] ?? 0);
        $gids = $groupsByNote[$nid] ?? [];
        $objectsNotes[(string) $nid] = [
            'note_id' => (string) $nid,
            'created_at' => th_mcp_export_iso_utc((string) ($nr['created_at'] ?? '')),
            'author_user_id' => (string) $authorId,
            'day' => substr((string) ($nr['entry_date'] ?? ''), 0, 10),
            'group_ids' => array_map(static fn (int $g): string => (string) $g, $gids),
        ];
    }

    $objectsThoughts = [];
    foreach ($thoughtById as $tid => $t) {
        $noteId = (int) ($t['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        $authorUserId = $noteRow !== null ? (int) ($noteRow['user_id'] ?? 0) : 0;

        $objectsThoughts[(string) $tid] = [
            'thought_id' => (string) $tid,
            'note_id' => (string) $noteId,
            'created_at' => th_mcp_export_iso_utc((string) ($t['created_at'] ?? '')),
            'author_user_id' => (string) $authorUserId,
            'text' => (string) ($t['body'] ?? ''),
        ];
    }

    $objectsComments = [];
    foreach ($commentById as $cid => $c) {
        $objectsComments[(string) $cid] = [
            'comment_id' => (string) $cid,
            'parent_kind' => 'thought',
            'parent_id' => (string) (int) ($c['thought_id'] ?? 0),
            'created_at' => th_mcp_export_iso_utc((string) ($c['created_at'] ?? '')),
            'author_user_id' => (string) (int) ($c['user_id'] ?? 0),
            'text' => (string) ($c['body'] ?? ''),
        ];
    }

    $objectsReactions = [];
    foreach ($reactionById as $rid => $r) {
        $objectsReactions[(string) $rid] = [
            'reaction_id' => (string) $rid,
            'parent_kind' => 'thought',
            'parent_id' => (string) (int) ($r['thought_id'] ?? 0),
            'created_at' => th_mcp_export_iso_utc((string) ($r['created_at'] ?? '')),
            'author_user_id' => (string) (int) ($r['user_id'] ?? 0),
            'emoji' => (string) ($r['emoji'] ?? ''),
        ];
    }

    $objectsMedia = [];
    foreach ($mediaById as $mid => $m) {
        $noteId = (int) ($m['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        $ownerId = $noteRow !== null ? (int) ($noteRow['user_id'] ?? 0) : 0;
        $relPath = (string) ($m['file_path'] ?? '');
        $mime = mcp_media_mime_from_relative_path($relPath);

        $viewUrl = null;
        $thumbUrl = null;
        if ($includeUrls && $ownerId > 0) {
            $viewUrl = mcp_make_signed_media_url($ownerId, (string) $mid, 'full', TH_MCP_EXPORT_MEDIA_TTL_SECONDS);
            $thumbUrl = mcp_make_signed_media_url($ownerId, (string) $mid, 'thumb', TH_MCP_EXPORT_MEDIA_TTL_SECONDS);
            if ($viewUrl === '' || $thumbUrl === '') {
                return [
                    'text' => json_encode(['error' => 'Could not build signed media URLs.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'is_error' => true,
                ];
            }
        }

        $objectsMedia[(string) $mid] = [
            'media_id' => (string) $mid,
            'note_id' => (string) $noteId,
            'created_at' => th_mcp_export_iso_utc((string) ($m['created_at'] ?? '')),
            'owner_user_id' => (string) $ownerId,
            'kind' => 'photo',
            'mime_type' => $mime,
            'view_url' => $viewUrl,
            'thumb_url' => $thumbUrl,
        ];
    }

    $events = [];

    foreach ($noteRows as $nr) {
        if (!is_array($nr)) {
            continue;
        }
        $nid = (int) ($nr['id'] ?? 0);
        $authorId = (int) ($nr['user_id'] ?? 0);
        if ($nid <= 0) {
            continue;
        }
        $gids = $groupsByNote[$nid] ?? [];
        $vis = th_mcp_export_event_visibility($viewerUserId, $authorId, $gids);
        $events[] = [
            'ts' => th_mcp_export_iso_utc((string) ($nr['created_at'] ?? '')),
            'type' => 'note_created',
            'actor_user_id' => (string) $authorId,
            'note_id' => (string) $nid,
            'visibility' => $vis,
        ];
    }

    foreach ($thoughtById as $tid => $t) {
        $noteId = (int) ($t['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        if ($noteRow === null) {
            continue;
        }
        $authorId = (int) ($noteRow['user_id'] ?? 0);
        $gids = $groupsByNote[$noteId] ?? [];
        $vis = th_mcp_export_event_visibility($viewerUserId, $authorId, $gids);
        $events[] = [
            'ts' => th_mcp_export_iso_utc((string) ($t['created_at'] ?? '')),
            'type' => 'thought_added',
            'actor_user_id' => (string) $authorId,
            'note_id' => (string) $noteId,
            'thought_id' => (string) $tid,
            'visibility' => $vis,
        ];
    }

    foreach ($commentById as $cid => $c) {
        $thoughtId = (int) ($c['thought_id'] ?? 0);
        $t = $thoughtById[$thoughtId] ?? null;
        if ($t === null) {
            continue;
        }
        $noteId = (int) ($t['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        if ($noteRow === null) {
            continue;
        }
        $noteAuthorId = (int) ($noteRow['user_id'] ?? 0);
        $gids = $groupsByNote[$noteId] ?? [];
        $vis = th_mcp_export_event_visibility($viewerUserId, $noteAuthorId, $gids);
        $actorId = (int) ($c['user_id'] ?? 0);
        $events[] = [
            'ts' => th_mcp_export_iso_utc((string) ($c['created_at'] ?? '')),
            'type' => 'comment_added',
            'actor_user_id' => (string) $actorId,
            'note_id' => (string) $noteId,
            'thought_id' => (string) $thoughtId,
            'comment_id' => (string) $cid,
            'visibility' => $vis,
        ];
    }

    foreach ($reactionById as $rid => $r) {
        $thoughtId = (int) ($r['thought_id'] ?? 0);
        $t = $thoughtById[$thoughtId] ?? null;
        if ($t === null) {
            continue;
        }
        $noteId = (int) ($t['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        if ($noteRow === null) {
            continue;
        }
        $noteAuthorId = (int) ($noteRow['user_id'] ?? 0);
        $gids = $groupsByNote[$noteId] ?? [];
        $vis = th_mcp_export_event_visibility($viewerUserId, $noteAuthorId, $gids);
        $actorId = (int) ($r['user_id'] ?? 0);
        $events[] = [
            'ts' => th_mcp_export_iso_utc((string) ($r['created_at'] ?? '')),
            'type' => 'reaction_added',
            'actor_user_id' => (string) $actorId,
            'note_id' => (string) $noteId,
            'thought_id' => (string) $thoughtId,
            'reaction_id' => (string) $rid,
            'visibility' => $vis,
        ];
    }

    foreach ($mediaById as $mid => $m) {
        $noteId = (int) ($m['note_id'] ?? 0);
        $noteRow = null;
        foreach ($noteRows as $nr) {
            if (is_array($nr) && (int) ($nr['id'] ?? 0) === $noteId) {
                $noteRow = $nr;
                break;
            }
        }
        if ($noteRow === null) {
            continue;
        }
        $noteAuthorId = (int) ($noteRow['user_id'] ?? 0);
        $gids = $groupsByNote[$noteId] ?? [];
        $vis = th_mcp_export_event_visibility($viewerUserId, $noteAuthorId, $gids);
        $events[] = [
            'ts' => th_mcp_export_iso_utc((string) ($m['created_at'] ?? '')),
            'type' => 'media_attached',
            'actor_user_id' => (string) $noteAuthorId,
            'note_id' => (string) $noteId,
            'media_id' => (string) $mid,
            'visibility' => $vis,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        return strcmp((string) ($a['ts'] ?? ''), (string) ($b['ts'] ?? ''));
    });

    $export = [
        'export_version' => '1',
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        'range' => [
            'from' => $fromRaw,
            'to' => $toRaw,
        ],
        'viewer' => [
            'user_id' => (string) $viewerUserId,
        ],
        'timezone' => $tzName !== '' ? $tzName : 'UTC',
        'objects' => [
            'people' => $peopleMap,
            'notes' => $objectsNotes,
            'thoughts' => $objectsThoughts,
            'comments' => $objectsComments,
            'reactions' => $objectsReactions,
            'media' => $objectsMedia,
        ],
        'events' => $events,
    ];

    return [
        'text' => json_encode($export, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'is_error' => false,
    ];
}

function th_mcp_export_iso_utc(string $mysqlDatetime): string
{
    $mysqlDatetime = trim($mysqlDatetime);
    if ($mysqlDatetime === '') {
        return '';
    }
    try {
        // Naive MySQL DATETIME is UTC in storage; do not use PHP's default timezone for parsing.
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) {
        return $mysqlDatetime;
    }
}

/**
 * @param list<int> $groupIds
 * @return array{visible_to_me:bool,shared_by_me:bool,shared_with_me:bool,shared_group_ids:list<string>}
 */
function th_mcp_export_event_visibility(int $viewerUserId, int $noteAuthorUserId, array $groupIds): array
{
    $hasGroups = $groupIds !== [];
    $sharedByMe = ($viewerUserId === $noteAuthorUserId && $hasGroups);
    $sharedWithMe = ($viewerUserId !== $noteAuthorUserId);

    return [
        'visible_to_me' => true,
        'shared_by_me' => $sharedByMe,
        'shared_with_me' => $sharedWithMe,
        'shared_group_ids' => array_map(static fn (int $g): string => (string) $g, $groupIds),
    ];
}
