<?php
/**
 * MCP tool record_gratitude — uses app DB + timezone + validation (no duplicated rules).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/note_thoughts.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';
require_once dirname(__DIR__) . '/includes/validation.php';

/**
 * @return array{text:string,is_error:bool,action:string,photo_count:int,request_id:string}
 */
function th_mcp_record_gratitude_run(PDO $pdo, int $userId, array $arguments): array
{
    $requestId = bin2hex(random_bytes(8));

    $textRaw = isset($arguments['text']) && is_string($arguments['text'])
        ? trim($arguments['text'])
        : '';

    /** @var list<int> $photoIdInts */
    $photoIdInts = [];
    if (isset($arguments['photo_id']) && is_string($arguments['photo_id'])) {
        $s = trim($arguments['photo_id']);
        if ($s !== '' && ctype_digit($s)) {
            $photoIdInts[] = (int) $s;
        }
    }
    if (isset($arguments['photo_ids'])) {
        if (is_string($arguments['photo_ids'])) {
            $t = trim($arguments['photo_ids']);
            if ($t !== '' && ctype_digit($t)) {
                $photoIdInts[] = (int) $t;
            }
        } elseif (is_array($arguments['photo_ids'])) {
            foreach ($arguments['photo_ids'] as $p) {
                if (is_string($p)) {
                    $t = trim($p);
                    if ($t !== '' && ctype_digit($t)) {
                        $photoIdInts[] = (int) $t;
                    }
                } elseif (is_int($p) && $p > 0) {
                    $photoIdInts[] = $p;
                }
            }
        }
    }
    $seen = [];
    $photoIdInts = array_values(array_filter(array_unique($photoIdInts, SORT_NUMERIC), static function (int $id) use (&$seen): bool {
        if ($id <= 0 || isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;

        return true;
    }));

    $photoCountRequested = count($photoIdInts);

    if ($textRaw === '' && $photoCountRequested === 0) {
        return [
            'text' => 'This gratitude entry couldn\'t be saved because the text was missing and no photos were provided.',
            'is_error' => true,
            'action' => 'updated_entry',
            'photo_count' => 0,
            'request_id' => $requestId,
        ];
    }

    if ($textRaw !== '') {
        $len = validation_utf8_length($textRaw);
        if ($len > NOTE_THOUGHT_BODY_MAX_LENGTH) {
            return [
                'text' => 'Something went wrong while saving this gratitude. Please try again.',
                'is_error' => true,
                'action' => 'updated_entry',
                'photo_count' => 0,
                'request_id' => $requestId,
            ];
        }
    }

    $tz = user_timezone_get($pdo, $userId);
    $defaultYmd = user_local_today_ymd($tz);

    $entryDate = $defaultYmd;
    if (isset($arguments['date']) && is_string($arguments['date'])) {
        $d = trim($arguments['date']);
        if ($d !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return [
                    'text' => 'Something went wrong while saving this gratitude. Please try again.',
                    'is_error' => true,
                    'action' => 'updated_entry',
                    'photo_count' => 0,
                    'request_id' => $requestId,
                ];
            }
            try {
                $dt = new DateTimeImmutable($d, new DateTimeZone('UTC'));
            } catch (Throwable) {
                return [
                    'text' => 'Something went wrong while saving this gratitude. Please try again.',
                    'is_error' => true,
                    'action' => 'updated_entry',
                    'photo_count' => 0,
                    'request_id' => $requestId,
                ];
            }
            $entryDate = $dt->format('Y-m-d');
        }
    }

    try {
        return th_mcp_record_gratitude_transaction(
            $pdo,
            $userId,
            $entryDate,
            $textRaw,
            $photoIdInts,
            $requestId
        );
    } catch (Throwable $e) {
        error_log('th_mcp_record_gratitude: ' . $e->getMessage());

        return [
            'text' => 'Something went wrong while saving this gratitude. Please try again.',
            'is_error' => true,
            'action' => 'updated_entry',
            'photo_count' => 0,
            'request_id' => $requestId,
        ];
    }
}

/**
 * @param list<int> $photoIdInts
 * @return array{text:string,is_error:bool,action:string,photo_count:int,request_id:string}
 */
function th_mcp_record_gratitude_transaction(
    PDO $pdo,
    int $userId,
    string $entryDate,
    string $textRaw,
    array $photoIdInts,
    string $requestId
): array {
    $photoCount = count($photoIdInts);

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            'SELECT id FROM notes WHERE user_id = ? AND entry_date = ? LIMIT 1'
        );
        $find->execute([$userId, $entryDate]);
        $existingNoteId = (int) $find->fetchColumn();

        $createdNewNote = false;
        $appendedThought = false;
        $targetNoteId = $existingNoteId;

        if ($targetNoteId <= 0) {
            $ins = $pdo->prepare(
                <<<'SQL'
                INSERT INTO notes (user_id, entry_date, visibility, created_at, updated_at)
                VALUES (?, ?, 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                SQL
            );
            $ins->execute([$userId, $entryDate]);
            $targetNoteId = (int) $pdo->lastInsertId();
            if ($targetNoteId <= 0) {
                throw new RuntimeException('note insert failed');
            }
            $createdNewNote = true;
        }

        $hasText = $textRaw !== '';
        if ($hasText) {
            $tstmt = $pdo->prepare(
                'INSERT INTO note_thoughts (note_id, body, is_private, created_at) VALUES (?, ?, 0, UTC_TIMESTAMP())'
            );
            $tstmt->execute([$targetNoteId, $textRaw]);
            $appendedThought = true;
        } elseif ($createdNewNote && $photoCount > 0) {
            $tstmt = $pdo->prepare(
                'INSERT INTO note_thoughts (note_id, body, is_private, created_at) VALUES (?, ?, 0, UTC_TIMESTAMP())'
            );
            $tstmt->execute([$targetNoteId, '']);
        }

        if ($photoCount > 0) {
            $placeholders = implode(',', array_fill(0, $photoCount, '?'));
            $verify = $pdo->prepare(
                <<<SQL
                SELECT nm.id
                FROM note_media nm
                INNER JOIN notes n ON n.id = nm.note_id
                WHERE nm.id IN ($placeholders) AND n.user_id = ?
                SQL
            );
            $verify->execute([...$photoIdInts, $userId]);
            $found = $verify->fetchAll(PDO::FETCH_COLUMN);
            $foundInts = array_map(static fn ($v): int => (int) $v, is_array($found) ? $found : []);
            sort($foundInts);
            $sortedReq = $photoIdInts;
            sort($sortedReq);
            if ($foundInts !== $sortedReq) {
                throw new RuntimeException('photo ownership mismatch');
            }

            $upd = $pdo->prepare(
                <<<'SQL'
                UPDATE note_media nm
                INNER JOIN notes n ON n.id = nm.note_id
                SET nm.note_id = ?
                WHERE nm.id = ? AND n.user_id = ?
                SQL
            );
            foreach ($photoIdInts as $mid) {
                $upd->execute([$targetNoteId, $mid, $userId]);
            }
        }

        $pdo->prepare(
            'UPDATE notes SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?'
        )->execute([$targetNoteId, $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($hasText && $photoCount > 0) {
        $msg = 'Saved this reflection and added the photos to today\'s gratitude.';
    } elseif ($createdNewNote && $hasText && $photoCount === 0) {
        $msg = 'Saved this as today\'s gratitude.';
    } elseif (!$createdNewNote && $hasText && $photoCount === 0) {
        $msg = 'Added this as a new thought to today\'s gratitude.';
    } elseif (!$createdNewNote && !$hasText && $photoCount > 0) {
        $msg = 'Added photos to today\'s gratitude.';
    } elseif ($createdNewNote && !$hasText && $photoCount > 0) {
        $msg = 'Created today\'s gratitude and added your photos.';
    } else {
        error_log(json_encode([
            'request_id' => $requestId,
            'user_id' => $userId,
            'action' => 'updated_entry',
            'photo_count' => $photoCount,
            'mcp_record_gratitude_branch' => 'unexpected',
        ], JSON_UNESCAPED_SLASHES));

        return [
            'text' => 'Something went wrong while saving this gratitude. Please try again.',
            'is_error' => true,
            'action' => 'updated_entry',
            'photo_count' => $photoCount,
            'request_id' => $requestId,
        ];
    }

    if ($createdNewNote) {
        $logAction = 'created_entry';
    } elseif ($appendedThought) {
        $logAction = 'appended_thought';
    } elseif ($photoCount > 0) {
        $logAction = 'attached_photos';
    } else {
        $logAction = 'updated_entry';
    }

    error_log(json_encode([
        'request_id' => $requestId,
        'user_id' => $userId,
        'action' => $logAction,
        'photo_count' => $photoCount,
    ], JSON_UNESCAPED_SLASHES));

    return [
        'text' => $msg,
        'is_error' => false,
        'action' => $logAction,
        'photo_count' => $photoCount,
        'request_id' => $requestId,
    ];
}
