<?php
/**
 * User data export queue, ZIP builder, retention, and worker helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/app_url.php';
require_once __DIR__ . '/group_helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/note_media.php';
require_once __DIR__ . '/user_timezone.php';

const USER_EXPORT_VERSION = '1.0';
const USER_EXPORT_RETENTION = 3;
const USER_EXPORT_STUCK_MINUTES = 45;
const USER_EXPORT_ERR_MAX_LEN = 2000;

/** @var int Mask for JSON encoding export payloads (UTF-8 sanitize). */
const USER_EXPORT_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE;

function user_export_storage_root(): string
{
    loadEnv();
    $configured = $_ENV['EXPORT_STORAGE_PATH'] ?? getenv('EXPORT_STORAGE_PATH');
    if (is_string($configured) && $configured !== '') {
        return $configured;
    }

    return dirname(__DIR__) . '/storage/exports';
}

function user_export_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM user_data_exports LIMIT 1');

        return true;
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            return false;
        }

        throw $e;
    }
}

function user_export_worker_token_valid(mixed $token): bool
{
    $expected = env_var('EXPORT_WORKER_TOKEN');
    if ($expected === '') {
        return false;
    }

    return is_string($token) && hash_equals($expected, $token);
}

function user_export_iso8601_utc(?DateTimeImmutable $dt): string
{
    if ($dt === null) {
        return '';
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function user_export_fail_stuck_jobs(PDO $pdo): int
{
    $minutes = (int) USER_EXPORT_STUCK_MINUTES;
    $stmt = $pdo->prepare(
        "UPDATE user_data_exports
         SET status = ?, completed_at = UTC_TIMESTAMP(),
             error_message = ?
         WHERE status = ? AND started_at IS NOT NULL
           AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$minutes} MINUTE)"
    );

    $stmt->execute([
        'failed',
        'Export timed out (host limit). You can request a new export from Me.',
        'running',
    ]);

    return $stmt->rowCount();
}

/**
 * Atomically claim the oldest queued job (transaction + row lock).
 *
 * @return array<string, mixed>|null
 */
function user_export_claim_next_job(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        user_export_fail_stuck_jobs($pdo);

        $pickSql = <<<'SQL'
SELECT id FROM user_data_exports
WHERE status = 'queued'
ORDER BY requested_at ASC
LIMIT 1
FOR UPDATE
SQL;

        $stmt = $pdo->query($pickSql);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $pdo->commit();

            return null;
        }

        $exportId = (int) $id;
        $upd = $pdo->prepare(
            'UPDATE user_data_exports
             SET status = ?, started_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = ?'
        );
        $upd->execute(['running', $exportId, 'queued']);
        if ($upd->rowCount() === 0) {
            $pdo->commit();

            return null;
        }

        $sel = $pdo->prepare('SELECT * FROM user_data_exports WHERE id = ? LIMIT 1');
        $sel->execute([$exportId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function user_export_has_active_job(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_data_exports WHERE user_id = ? AND status IN (?, ?) LIMIT 1'
    );
    $stmt->execute([$userId, 'queued', 'running']);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array{ok: true, id: int}|array{ok: false, error: string}
 */
function user_export_enqueue(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }

    if (user_export_has_active_job($pdo, $userId)) {
        return ['ok' => false, 'error' => 'You already have an export in progress. Check back when it finishes.'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO user_data_exports (user_id, status, requested_at) VALUES (?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([$userId, 'queued']);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function user_export_mark_failed(PDO $pdo, int $exportId, string $message): void
{
    $trim = mb_strlen($message) > USER_EXPORT_ERR_MAX_LEN
        ? mb_substr($message, 0, USER_EXPORT_ERR_MAX_LEN)
        : $message;

    $stmt = $pdo->prepare(
        'UPDATE user_data_exports
         SET status = ?, completed_at = UTC_TIMESTAMP(), error_message = ?
         WHERE id = ?'
    );
    $stmt->execute(['failed', $trim, $exportId]);
}

/**
 * @return list<array<string, mixed>>
 */
function user_export_list_for_user(PDO $pdo, int $userId, int $limit = 12): array
{
    $stmt = $pdo->prepare(
        'SELECT id, status, requested_at, started_at, completed_at, deleted_at, file_size, error_message
         FROM user_data_exports
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT ' . max(1, min(50, $limit))
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * Remove a ready export ZIP from disk and mark the row deleted_by_user (audit retention).
 *
 * @return array{ok: true}|array{ok: false, error: string}
 */
function user_export_user_delete_ready(PDO $pdo, int $userId, int $exportId): array
{
    if ($exportId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, file_path FROM user_data_exports
             WHERE id = ? AND user_id = ? AND status = ?
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$exportId, $userId, 'ready']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'not_found'];
        }

        $path = isset($row['file_path']) && is_string($row['file_path']) ? $row['file_path'] : null;
        if ($path !== null && $path !== '') {
            user_export_delete_relative_file($path);
        }

        $upd = $pdo->prepare(
            'UPDATE user_data_exports
             SET status = ?, deleted_at = UTC_TIMESTAMP(), file_path = NULL, file_size = NULL
             WHERE id = ? AND user_id = ? AND status = ?'
        );
        $upd->execute(['deleted_by_user', $exportId, $userId, 'ready']);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'conflict'];
        }

        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function user_export_delete_row_and_file(PDO $pdo, int $exportId, ?string $relativePath): void
{
    if ($relativePath !== null && $relativePath !== '') {
        user_export_delete_relative_file($relativePath);
    }

    $del = $pdo->prepare('DELETE FROM user_data_exports WHERE id = ?');
    $del->execute([$exportId]);
}

function user_export_delete_relative_file(string $relativePath): void
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return;
    }

    $root = realpath(user_export_storage_root());
    if ($root === false) {
        return;
    }

    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($full !== false && is_file($full) && strncmp($full, $root, strlen($root)) === 0) {
        @unlink($full);
        $dir = dirname($full);
        if ($dir !== $root && is_dir($dir)) {
            @rmdir($dir);
        }
    }
}

/**
 * Keep the newest USER_EXPORT_RETENTION rows per user; remove older rows and ZIP files.
 */
function user_export_prune_old_exports(PDO $pdo, int $userId): void
{
    // Never delete queued/running rows—only trim finished archives (ready/failed).
    $stmt = $pdo->prepare(
        "SELECT id, file_path FROM user_data_exports
         WHERE user_id = ? AND status IN ('ready', 'failed')
         ORDER BY id DESC"
    );
    $stmt->execute([$userId]);
    $finished = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($finished) || count($finished) <= USER_EXPORT_RETENTION) {
        return;
    }

    $drop = array_slice($finished, USER_EXPORT_RETENTION);
    foreach ($drop as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $path = isset($row['file_path']) && is_string($row['file_path']) ? $row['file_path'] : null;
        if ($id > 0) {
            user_export_delete_row_and_file($pdo, $id, $path);
        }
    }
}

function user_export_send_ready_email(PDO $pdo, int $userId): void
{
    $to = user_notification_email($pdo, $userId);
    if ($to === null || $to === '') {
        return;
    }

    $meUrl = app_absolute_url('/me.php');
    $body = <<<TXT
Your Thankhill data export is ready.

Open your Me page while signed in to download your ZIP file:
{$meUrl}

For security, we don't attach or link directly to the file from email.

— Thankhill
TXT;

    send_email($to, 'Your Thankhill data export is ready', $body);
}

/**
 * Build ZIP for export job and mark ready (or failed).
 *
 * @param array<string, mixed> $job
 */
function user_export_build_and_finalize(PDO $pdo, array $job): void
{
    if (!class_exists(ZipArchive::class)) {
        user_export_mark_failed($pdo, (int) $job['id'], 'ZIP support is not available on this server.');

        return;
    }

    $exportId = (int) $job['id'];
    $userId = (int) $job['user_id'];

    $userStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $displayName = $userStmt->fetchColumn();
    $displayName = is_string($displayName) ? $displayName : 'Me';

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $folderDay = $nowUtc->format('Y-m-d');
    $zipBasename = 'thankhill-export-' . $folderDay . '.zip';
    $innerRoot = 'thankhill-export-' . $folderDay . '/';

    $relDir = $userId . '/' . $exportId;
    $relZip = $relDir . '/' . $zipBasename;

    $root = user_export_storage_root();
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        user_export_mark_failed($pdo, $exportId, 'Could not create export storage directory.');

        return;
    }

    $destDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    if (!is_dir($destDir) && !@mkdir($destDir, 0770, true) && !is_dir($destDir)) {
        user_export_mark_failed($pdo, $exportId, 'Could not create export job directory.');

        return;
    }

    $zipAbs = $destDir . DIRECTORY_SEPARATOR . $zipBasename;

    $tmpZip = $zipAbs . '.tmp.' . bin2hex(random_bytes(4));

    try {
        [$notesPayload, $photosCopied] = user_export_build_notes_payload($pdo, $userId);
        [$myCommentsPayload, $myCommentsCount] = user_export_build_my_comments_payload($pdo, $userId);

        $photosCount = count($photosCopied);
        $notesCount = count($notesPayload['notes']);

        $metadata = [
            'app' => 'Thankhill',
            'export_version' => USER_EXPORT_VERSION,
            'generated_at' => user_export_iso8601_utc($nowUtc),
            'notes_count' => $notesCount,
            'photos_count' => $photosCount,
            'my_comments_count' => $myCommentsCount,
        ];

        $readme = user_export_readme_text($nowUtc, $metadata);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open ZIP for writing.');
        }

        $zip->addFromString($innerRoot . 'README.txt', $readme);
        $zip->addFromString($innerRoot . 'metadata.json', json_encode($metadata, USER_EXPORT_JSON_FLAGS) . "\n");
        $zip->addFromString($innerRoot . 'notes.json', json_encode($notesPayload, USER_EXPORT_JSON_FLAGS) . "\n");
        $zip->addFromString($innerRoot . 'my_comments.json', json_encode($myCommentsPayload, USER_EXPORT_JSON_FLAGS) . "\n");

        foreach ($photosCopied as $item) {
            if (!$zip->addFile($item['abs'], $innerRoot . $item['zip_path'])) {
                throw new RuntimeException('Could not add photo to ZIP: ' . $item['zip_path']);
            }
        }

        $zip->close();

        if (!@rename($tmpZip, $zipAbs)) {
            throw new RuntimeException('Could not finalize ZIP file.');
        }

        @chmod($zipAbs, 0660);

        $size = filesize($zipAbs);
        if ($size === false) {
            throw new RuntimeException('Could not read export file size.');
        }

        $fin = $pdo->prepare(
            'UPDATE user_data_exports
             SET status = ?, completed_at = UTC_TIMESTAMP(), file_path = ?, file_size = ?, error_message = NULL
             WHERE id = ?'
        );
        $fin->execute(['ready', $relZip, $size, $exportId]);

        user_export_send_ready_email($pdo, $userId);
        user_export_prune_old_exports($pdo, $userId);
    } catch (Throwable $e) {
        @unlink($tmpZip);
        if (is_file($zipAbs)) {
            @unlink($zipAbs);
        }
        error_log('user_export_build: ' . $e->getMessage());
        user_export_mark_failed($pdo, $exportId, $e->getMessage());
    }
}

/**
 * @return array{0: array<string, mixed>, 1: list<array{abs: string, zip_path: string}>}
 */
function user_export_build_notes_payload(PDO $pdo, int $userId): array
{
    $notesStmt = $pdo->prepare(
        'SELECT id, entry_date, created_at FROM notes WHERE user_id = ? ORDER BY entry_date ASC, id ASC'
    );
    $notesStmt->execute([$userId]);
    $noteRows = $notesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $notesOut = [];
    $photosCopied = [];

    $groupsStmt = $pdo->prepare(
        'SELECT g.name FROM note_groups ng
         INNER JOIN `groups` g ON g.id = ng.group_id
         WHERE ng.note_id = ?
         ORDER BY g.name ASC'
    );

    $thoughtsStmt = $pdo->prepare(
        'SELECT id, body, created_at FROM note_thoughts WHERE note_id = ? ORDER BY created_at ASC, id ASC'
    );

    $commentsStmt = $pdo->prepare(
        'SELECT tc.created_at, tc.body, u.display_name AS author_name
         FROM thought_comments tc
         INNER JOIN users u ON u.id = tc.user_id
         WHERE tc.thought_id IN (SELECT id FROM note_thoughts WHERE note_id = ?)
         ORDER BY tc.created_at ASC, tc.id ASC'
    );

    $reactionsStmt = $pdo->prepare(
        'SELECT tr.created_at, tr.emoji, u.display_name AS author_name
         FROM thought_reactions tr
         INNER JOIN users u ON u.id = tr.user_id
         WHERE tr.thought_id IN (SELECT id FROM note_thoughts WHERE note_id = ?)
         ORDER BY tr.created_at ASC, tr.id ASC'
    );

    $mediaStmt = $pdo->prepare(
        'SELECT id, file_path, created_at FROM note_media WHERE note_id = ? ORDER BY created_at ASC, id ASC'
    );

    foreach ($noteRows as $nr) {
        if (!is_array($nr)) {
            continue;
        }
        $noteId = (int) $nr['id'];

        $groupsStmt->execute([$noteId]);
        $groupNames = $groupsStmt->fetchAll(PDO::FETCH_COLUMN);
        $shared = [];
        foreach ($groupNames as $gn) {
            if (is_string($gn) && $gn !== '') {
                $shared[] = $gn;
            }
        }

        $createdNote = user_datetime_immutable_utc((string) $nr['created_at']);

        $thoughtsStmt->execute([$noteId]);
        $thoughtRows = $thoughtsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $thoughtsJson = [];
        foreach ($thoughtRows as $tr) {
            if (!is_array($tr)) {
                continue;
            }
            $tc = user_datetime_immutable_utc((string) $tr['created_at']);
            $thoughtsJson[] = [
                'created_at' => user_export_iso8601_utc($tc),
                'text' => (string) $tr['body'],
            ];
        }

        $commentsStmt->execute([$noteId]);
        $commentRows = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $commentsJson = [];
        foreach ($commentRows as $cr) {
            if (!is_array($cr)) {
                continue;
            }
            $cc = user_datetime_immutable_utc((string) $cr['created_at']);
            $commentsJson[] = [
                'created_at' => user_export_iso8601_utc($cc),
                'author_name' => (string) $cr['author_name'],
                'text' => (string) $cr['body'],
            ];
        }

        $reactionsStmt->execute([$noteId]);
        $reactionRows = $reactionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $reactionsJson = [];
        foreach ($reactionRows as $rr) {
            if (!is_array($rr)) {
                continue;
            }
            $rc = user_datetime_immutable_utc((string) $rr['created_at']);
            $reactionsJson[] = [
                'reacted_at' => user_export_iso8601_utc($rc),
                'author_name' => (string) $rr['author_name'],
                'emoji' => (string) $rr['emoji'],
            ];
        }

        $mediaStmt->execute([$noteId]);
        $mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $photosJson = [];
        foreach ($mediaRows as $mr) {
            if (!is_array($mr)) {
                continue;
            }
            $mid = (int) $mr['id'];
            $relPath = (string) $mr['file_path'];
            $abs = note_media_resolve_absolute($relPath);
            if ($abs === null || !is_readable($abs)) {
                continue;
            }

            $mc = user_datetime_immutable_utc((string) $mr['created_at']);
            $stamp = $mc !== null ? $mc->format('Y-m-d_H-i-s') : 'unknown-date';
            $base = pathinfo($relPath, PATHINFO_FILENAME);
            $base = $base !== '' ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) : 'photo';
            $ext = strtolower((string) pathinfo($relPath, PATHINFO_EXTENSION));
            if ($ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png') {
                $ext = 'jpg';
            }

            $zipPath = 'photos/' . $stamp . '_' . $base . '.' . $ext;
            $photosCopied[] = ['abs' => $abs, 'zip_path' => $zipPath];

            $photosJson[] = [
                'uploaded_at' => user_export_iso8601_utc($mc),
                'file_name' => $zipPath,
            ];
        }

        $notesOut[] = [
            'created_at' => user_export_iso8601_utc($createdNote),
            'shared_with_groups' => $shared,
            'thoughts' => $thoughtsJson,
            'comments' => $commentsJson,
            'reactions' => $reactionsJson,
            'photos' => $photosJson,
        ];
    }

    $userStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $dn = $userStmt->fetchColumn();

    $payload = [
        'user' => ['display_name' => is_string($dn) ? $dn : 'Me'],
        'notes' => $notesOut,
    ];

    return [$payload, $photosCopied, ['notes_count' => count($notesOut), 'photos_count' => count($photosCopied)]];
}

/**
 * Comments I wrote on other people’s notes (no note body).
 *
 * @return array{0: array<string, mixed>, 1: int}
 */
function user_export_build_my_comments_payload(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        <<<'SQL'
SELECT tc.created_at, tc.body,
       owner.display_name AS note_author_name,
       n.created_at AS note_created_at
FROM thought_comments tc
INNER JOIN note_thoughts th ON th.id = tc.thought_id
INNER JOIN notes n ON n.id = th.note_id
INNER JOIN users owner ON owner.id = n.user_id
WHERE tc.user_id = ? AND n.user_id <> ?
ORDER BY tc.created_at ASC, tc.id ASC
SQL
    );
    $stmt->execute([$userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $comments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $dt = user_datetime_immutable_utc((string) $row['created_at']);
        $noteDt = user_datetime_immutable_utc((string) $row['note_created_at']);
        $comments[] = [
            'commented_at' => user_export_iso8601_utc($dt),
            'note_author_name' => (string) $row['note_author_name'],
            'note_created_at' => user_export_iso8601_utc($noteDt),
            'text' => (string) $row['body'],
        ];
    }

    $userStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $dn = $userStmt->fetchColumn();

    return [
        [
            'user' => ['display_name' => is_string($dn) ? $dn : 'Me'],
            'comments' => $comments,
        ],
        count($comments),
    ];
}

/**
 * @param array<string, int> $metadata
 */
function user_export_readme_text(DateTimeImmutable $generatedUtc, array $metadata): string
{
    $when = $generatedUtc->format('Y-m-d H:i:s') . ' UTC';
    $notes = (int) ($metadata['notes_count'] ?? 0);
    $photos = (int) ($metadata['photos_count'] ?? 0);
    $extComments = (int) ($metadata['my_comments_count'] ?? 0);

    return <<<TXT
Thankhill personal data export
Generated: {$when}

Counts in this archive (see metadata.json): {$notes} notes, {$photos} photos, {$extComments} comments you wrote on others’ entries.

What’s inside
-------------
- README.txt — this file
- metadata.json — export version and counts
- notes.json — your notes, thoughts, group names, comments & reactions on your entries, photo file names
- my_comments.json — short comments you wrote on other people’s shared entries (no copies of their note text)
- photos/ — JPEG/PNG files you uploaded to your notes

Privacy notes
-------------
- Names in this export are display names only (how people appear in Thankhill).
- Email addresses and internal database IDs are not included in these files.
- Other people’s private content is not included; only your notes and media, plus comments you chose to write elsewhere.

Enjoy revisiting your gratitude moments.

TXT;
}

/**
 * Resolve filesystem path for a ready export (security: caller validates owner + status).
 */
function user_export_resolve_absolute_zip(string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }

    $root = realpath(user_export_storage_root());
    if ($root === false) {
        return null;
    }

    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($full === false || !is_file($full)) {
        return null;
    }

    if (strncmp($full, $root, strlen($root)) !== 0) {
        return null;
    }

    return $full;
}
