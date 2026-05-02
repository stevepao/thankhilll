<?php
/**
 * MCP tool list_recent_photos — recent note_media for the authenticated user + signed URLs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/media_signing.php';

/** Same as MCP JSON bodies: substitute invalid UTF-8 so json_encode cannot throw on DB paths. */
const TH_MCP_LIST_RECENT_PHOTOS_JSON = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * @return array{text:string,is_error:bool}
 */
function th_mcp_list_recent_photos_run(PDO $pdo, int $userId, array $arguments): array
{
    if (mcp_media_signing_secret() === '') {
        return [
            'text' => json_encode(
                [
                    'error' => 'MCP_MEDIA_SIGNING_KEY is not configured; cannot generate photo URLs.',
                ],
                TH_MCP_LIST_RECENT_PHOTOS_JSON
            ),
            'is_error' => true,
        ];
    }

    $limit = 10;
    if (isset($arguments['limit'])) {
        $l = $arguments['limit'];
        if (is_int($l)) {
            $limit = $l;
        } elseif (is_float($l)) {
            $limit = (int) $l;
        } elseif (is_string($l) && ctype_digit(trim($l))) {
            $limit = (int) trim($l);
        }
        $limit = max(1, min(50, $limit));
    }

    $sinceClause = '';
    $params = [$userId];
    if (isset($arguments['since']) && is_string($arguments['since']) && trim($arguments['since']) !== '') {
        try {
            $sinceDt = new DateTimeImmutable(trim($arguments['since']));
            $sinceClause = ' AND nm.created_at >= ?';
            $params[] = $sinceDt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return [
                'text' => json_encode(
                    ['error' => 'Invalid since date/time.'],
                    TH_MCP_LIST_RECENT_PHOTOS_JSON
                ),
                'is_error' => true,
            ];
        }
    }

    // Integer literal — PDO/MySQL native prepared statements often reject bound LIMIT placeholders.
    $limitInt = max(1, min(50, (int) $limit));

    $sql = <<<SQL
        SELECT nm.id, nm.created_at, nm.file_path
        FROM note_media nm
        INNER JOIN notes n ON n.id = nm.note_id
        WHERE n.user_id = ?
        {$sinceClause}
        ORDER BY nm.created_at DESC
        LIMIT {$limitInt}
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ttl = 300;

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $photoIdStr = (string) $pid;
        $relPath = (string) ($row['file_path'] ?? '');
        $mime = mcp_media_mime_from_relative_path($relPath);

        $createdMysql = (string) ($row['created_at'] ?? '');
        try {
            $createdIso = (new DateTimeImmutable($createdMysql))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            $createdIso = $createdMysql;
        }

        $viewUrl = mcp_make_signed_media_url($userId, $photoIdStr, 'full', $ttl);
        $thumbUrl = mcp_make_signed_media_url($userId, $photoIdStr, 'thumb', $ttl);
        if ($viewUrl === '' || $thumbUrl === '') {
            return [
                'text' => json_encode(
                    ['error' => 'Could not build signed URLs.'],
                    TH_MCP_LIST_RECENT_PHOTOS_JSON
                ),
                'is_error' => true,
            ];
        }

        $out[] = [
            'photo_id' => $photoIdStr,
            'created_at' => $createdIso,
            'mime_type' => $mime,
            'view_url' => $viewUrl,
            'thumb_url' => $thumbUrl,
        ];
    }

    return [
        'text' => json_encode($out, TH_MCP_LIST_RECENT_PHOTOS_JSON),
        'is_error' => false,
    ];
}
