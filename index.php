<?php
/**
 * index.php — Today: daily gratitude entry (one per calendar day) with timestamped thoughts.
 *
 * Requires an authenticated session and writes notes for the current user.
 */
declare(strict_types=1);

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

$userId = require_login();
$pdo = db();

$prefs = user_preferences_load($pdo, $userId);
$validationError = null;
/** @var 'create_first'|'note_meta'|'add_thought'|'thought_edit'|null */
$errorContext = null;

$formThoughtBodyValue = '';
$addThoughtBodyValue = '';
$stickyThoughtEditId = 0;
$stickyThoughtBodyValue = '';
$stickyThoughtIsPrivate = false;
$formThoughtPrivateSticky = false;
$addThoughtPrivateSticky = false;

$editSticky = false;
/** @var array<int, true> */
$editStickyGroupIds = [];
/** @var array<int, true> Media IDs still included when re-showing the edit form after an error */
$editStickyKeepMediaIds = [];

$shareGroups = groups_for_user_with_counts($pdo, $userId);

$parseTodayGroups = static function (array $post): array {
    $rawGroupIds = $post['group_ids'] ?? [];
    if (!is_array($rawGroupIds)) {
        $rawGroupIds = [];
    }
    $selectedGroupIds = [];
    foreach ($rawGroupIds as $gidRaw) {
        if (is_numeric($gidRaw)) {
            $selectedGroupIds[] = (int) $gidRaw;
        }
    }

    return array_values(array_unique(array_filter($selectedGroupIds, static fn (int $id): bool => $id > 0)));
};

$preselectedGroupIds = [];
if (($prefs['default_note_visibility'] ?? '') === 'last_used_groups') {
    foreach ($prefs['last_used_group_ids'] ?? [] as $gid) {
        $gid = (int) $gid;
        if ($gid > 0 && user_is_group_member($pdo, $userId, $gid)) {
            $preselectedGroupIds[$gid] = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $todayAction = isset($_POST['today_action']) ? (string) $_POST['today_action'] : 'create_first';

    if ($todayAction === 'update_note') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $selectedGroupIds = $parseTodayGroups($_POST);

        $authorizedEdit = $noteId > 0 && user_can_edit_note_today($pdo, $userId, $noteId);

        if (!$authorizedEdit) {
            $validationError = 'You can\'t edit this note.';
        }

        /** @var list<int> $existingIds */
        $existingIds = [];
        /** @var array<int, true> $existingIdSet */
        $existingIdSet = [];
        if ($authorizedEdit) {
            $existingMedia = note_media_for_note($pdo, $noteId);
            $existingIds = array_column($existingMedia, 'id');
            foreach ($existingIds as $eid) {
                $existingIdSet[(int) $eid] = true;
            }
        }

        if ($validationError === null) {
            foreach ($selectedGroupIds as $gid) {
                if (!user_is_group_member($pdo, $userId, $gid)) {
                    $validationError = 'Invalid group selection.';
                    break;
                }
            }
        }

        $rawKeeps = $_POST['keep_media_ids'] ?? [];
        if (!is_array($rawKeeps)) {
            $rawKeeps = [];
        }
        $requestedKeeps = [];
        foreach ($rawKeeps as $r) {
            if (is_numeric($r)) {
                $requestedKeeps[] = (int) $r;
            }
        }
        $requestedKeeps = array_values(array_unique(array_filter($requestedKeeps, static fn (int $id): bool => $id > 0)));

        $keepSet = [];
        foreach ($requestedKeeps as $mid) {
            if (isset($existingIdSet[$mid])) {
                $keepSet[$mid] = true;
            }
        }

        $validDeletes = [];
        foreach ($existingIds as $mid) {
            if (!isset($keepSet[$mid])) {
                $validDeletes[] = $mid;
            }
        }

        $uploadNorm = ['ok' => true, 'items' => []];
        if ($validationError === null && $authorizedEdit) {
            $uploadNorm = note_media_normalize_uploads_from_field('photos_edit');
            if (!$uploadNorm['ok']) {
                $validationError = $uploadNorm['error'];
            }
        }

        /** @var list<array{tmp:string,size:int}> $photoItems */
        $photoItems = ($uploadNorm['ok'] ?? false) ? $uploadNorm['items'] : [];

        if ($validationError === null && $authorizedEdit) {
            $keepCount = count($existingIds) - count($validDeletes);
            if ($keepCount < 0) {
                $keepCount = 0;
            }
            if ($keepCount + count($photoItems) > NOTE_MEDIA_MAX_FILES_PER_UPLOAD) {
                $validationError = 'Too many photos for one note.';
            }
        }

        if ($validationError !== null && $authorizedEdit) {
            $editSticky = true;
            $errorContext = 'note_meta';
            foreach ($selectedGroupIds as $gid) {
                $editStickyGroupIds[$gid] = true;
            }
            foreach (array_keys($keepSet) as $mid) {
                $editStickyKeepMediaIds[$mid] = true;
            }
        }

        if ($validationError === null && $authorizedEdit) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'UPDATE notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?'
                )->execute([$noteId, $userId]);

                $pdo->prepare('DELETE FROM note_groups WHERE note_id = ?')->execute([$noteId]);

                if (count($selectedGroupIds) > 0) {
                    $link = $pdo->prepare(
                        'INSERT INTO note_groups (note_id, group_id) VALUES (?, ?)'
                    );
                    foreach ($selectedGroupIds as $gid) {
                        $link->execute([$noteId, $gid]);
                    }
                }

                note_media_delete_rows_for_note($pdo, $noteId, $validDeletes);

                if (count($photoItems) > 0) {
                    $mediaResult = note_media_attach_to_note($pdo, $noteId, $photoItems);
                    if (!$mediaResult['ok']) {
                        throw new RuntimeException($mediaResult['error']);
                    }
                }

                $pdo->commit();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $validationError = $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Could not save your note. Please try again.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('index note update: ' . $e->getMessage());
                $validationError = 'Could not save your note. Please try again.';
            }

            if ($validationError !== null) {
                $editSticky = true;
                $errorContext = 'note_meta';
                foreach ($selectedGroupIds as $gid) {
                    $editStickyGroupIds[$gid] = true;
                }
                foreach (array_keys($keepSet) as $mid) {
                    $editStickyKeepMediaIds[$mid] = true;
                }
            } else {
                user_preferences_merge_save($pdo, $userId, [
                    'last_used_group_ids' => $selectedGroupIds,
                ]);
                header('Location: /index.php?note_updated=1');
                exit;
            }
        }
    } elseif ($todayAction === 'add_thought') {
        $addThoughtPrivateSticky = parse_thought_is_private_from_post($_POST);
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $addThoughtBodyValue = is_string($_POST['thought_body'] ?? null)
            ? (string) ($_POST['thought_body'] ?? '')
            : '';

        $authorized = $noteId > 0 && user_can_edit_note_today($pdo, $userId, $noteId);

        if (!$authorized) {
            $validationError = 'You can\'t add to this entry.';
        }

        $validated = validate_required_string($_POST['thought_body'] ?? null, NOTE_THOUGHT_BODY_MAX_LENGTH);
        if ($validationError === null && !$validated['ok']) {
            $validationError = $validated['error'];
        }

        if ($validationError !== null && $authorized) {
            $errorContext = 'add_thought';
        }

        if ($validationError === null && $authorized) {
            $pdo->beginTransaction();
            try {
                $isPrivate = $addThoughtPrivateSticky ? 1 : 0;
                $ins = $pdo->prepare(
                    'INSERT INTO note_thoughts (note_id, body, is_private, created_at) VALUES (?, ?, ?, NOW())'
                );
                $ins->execute([$noteId, $validated['value'], $isPrivate]);

                $pdo->prepare(
                    'UPDATE notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?'
                )->execute([$noteId, $userId]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('index add thought: ' . $e->getMessage());
                $validationError = 'Could not save your thought. Please try again.';
                $errorContext = 'add_thought';
            }

            if ($validationError === null) {
                header('Location: /index.php?thought_added=1');
                exit;
            }
        }
    } elseif ($todayAction === 'update_thought') {
        $thoughtId = (int) ($_POST['thought_id'] ?? 0);
        $stickyThoughtIsPrivate = parse_thought_is_private_from_post($_POST);
        $stickyThoughtBodyValue = is_string($_POST['thought_body'] ?? null)
            ? (string) ($_POST['thought_body'] ?? '')
            : '';

        $authorized = $thoughtId > 0 && user_can_edit_thought_today($pdo, $userId, $thoughtId);

        if (!$authorized) {
            $validationError = 'You can\'t edit this thought.';
        }

        $validated = validate_required_string($_POST['thought_body'] ?? null, NOTE_THOUGHT_BODY_MAX_LENGTH);
        if ($validationError === null && !$validated['ok']) {
            $validationError = $validated['error'];
        }

        if ($validationError !== null && $authorized) {
            $errorContext = 'thought_edit';
            $stickyThoughtEditId = $thoughtId;
        }

        if ($validationError === null && $authorized) {
            $pdo->beginTransaction();
            try {
                $isPrivate = $stickyThoughtIsPrivate ? 1 : 0;
                $upd = $pdo->prepare(
                    <<<'SQL'
                    UPDATE note_thoughts t
                    INNER JOIN notes n ON n.id = t.note_id
                    SET t.body = ?, t.is_private = ?
                    WHERE t.id = ?
                      AND n.user_id = ?
                      AND n.entry_date = CURDATE()
                    SQL
                );
                $upd->execute([$validated['value'], $isPrivate, $thoughtId, $userId]);

                $nidStmt = $pdo->prepare('SELECT note_id FROM note_thoughts WHERE id = ? LIMIT 1');
                $nidStmt->execute([$thoughtId]);
                $parentNoteId = (int) $nidStmt->fetchColumn();
                if ($parentNoteId > 0) {
                    $pdo->prepare(
                        'UPDATE notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?'
                    )->execute([$parentNoteId, $userId]);
                }

                $pdo->commit();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $validationError = $e->getMessage();
                $errorContext = 'thought_edit';
                $stickyThoughtEditId = $thoughtId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('index update thought: ' . $e->getMessage());
                $validationError = 'Could not save your thought. Please try again.';
                $errorContext = 'thought_edit';
                $stickyThoughtEditId = $thoughtId;
            }

            if ($validationError === null) {
                header('Location: /index.php?thought_updated=1');
                exit;
            }
        }
    } elseif ($todayAction === 'delete_thought') {
        $thoughtId = (int) ($_POST['thought_id'] ?? 0);

        $authorized = $thoughtId > 0 && user_can_edit_thought_today($pdo, $userId, $thoughtId);

        if (!$authorized) {
            $validationError = 'You can\'t delete this thought.';
        }

        $parentNoteId = 0;
        if ($authorized) {
            $nidStmt = $pdo->prepare('SELECT note_id FROM note_thoughts WHERE id = ? LIMIT 1');
            $nidStmt->execute([$thoughtId]);
            $parentNoteId = (int) $nidStmt->fetchColumn();
            if ($parentNoteId <= 0) {
                $validationError = 'Thought not found.';
                $authorized = false;
            }
        }

        if ($authorized && note_thought_count_for_note($pdo, $parentNoteId) <= 1) {
            $validationError = 'Keep at least one gratitude moment for today.';
        }

        if ($validationError === null && $authorized) {
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare(
                    <<<'SQL'
                    DELETE t FROM note_thoughts t
                    INNER JOIN notes n ON n.id = t.note_id
                    WHERE t.id = ?
                      AND n.user_id = ?
                      AND n.entry_date = CURDATE()
                    SQL
                );
                $del->execute([$thoughtId, $userId]);
                $pdo->prepare(
                    'UPDATE notes SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?'
                )->execute([$parentNoteId, $userId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('index delete thought: ' . $e->getMessage());
                $validationError = 'Could not delete this thought. Please try again.';
            }

            if ($validationError === null) {
                header('Location: /index.php?thought_deleted=1');
                exit;
            }
        }
    } else {
        // First gratitude moment today: creates the daily note + first thought.
        $formThoughtPrivateSticky = parse_thought_is_private_from_post($_POST);
        $formThoughtBodyValue = is_string($_POST['thought_body'] ?? null)
            ? (string) ($_POST['thought_body'] ?? '')
            : '';

        $validated = validate_required_string($_POST['thought_body'] ?? null, NOTE_THOUGHT_BODY_MAX_LENGTH);
        $selectedGroupIds = $parseTodayGroups($_POST);

        if (!$validated['ok']) {
            $validationError = $validated['error'];
            $errorContext = 'create_first';
        } else {
            foreach ($selectedGroupIds as $gid) {
                if (!user_is_group_member($pdo, $userId, $gid)) {
                    $validationError = 'Invalid group selection.';
                    $errorContext = 'create_first';
                    break;
                }
            }
        }

        $uploadNorm = note_media_normalize_uploads_from_request();
        if ($validationError === null && !$uploadNorm['ok']) {
            $validationError = $uploadNorm['error'];
            $errorContext = 'create_first';
        }

        /** @var list<array{tmp:string,size:int}> $photoItems */
        $photoItems = ($uploadNorm['ok'] ?? false) ? $uploadNorm['items'] : [];

        if ($validationError === null) {
            $dup = $pdo->prepare(
                'SELECT id FROM notes WHERE user_id = ? AND entry_date = CURDATE() LIMIT 1'
            );
            $dup->execute([$userId]);
            if ($dup->fetchColumn()) {
                $validationError = 'Today\'s entry already exists. Refresh the page.';
                $errorContext = 'create_first';
            }
        }

        if ($validationError === null) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    <<<'SQL'
                    INSERT INTO notes (user_id, entry_date, visibility, created_at, updated_at)
                    VALUES (?, CURDATE(), 'private', NOW(), NOW())
                    SQL
                );
                $stmt->execute([$userId]);
                $noteId = (int) $pdo->lastInsertId();

                if ($noteId <= 0) {
                    throw new RuntimeException('Could not create today\'s entry.');
                }

                $firstPrivate = $formThoughtPrivateSticky ? 1 : 0;
                $tstmt = $pdo->prepare(
                    'INSERT INTO note_thoughts (note_id, body, is_private, created_at) VALUES (?, ?, ?, NOW())'
                );
                $tstmt->execute([$noteId, $validated['value'], $firstPrivate]);

                if (count($selectedGroupIds) > 0) {
                    $link = $pdo->prepare(
                        'INSERT INTO note_groups (note_id, group_id) VALUES (?, ?)'
                    );
                    foreach ($selectedGroupIds as $gid) {
                        $link->execute([$noteId, $gid]);
                    }
                }

                if (count($photoItems) > 0) {
                    $mediaResult = note_media_attach_to_note($pdo, $noteId, $photoItems);
                    if (!$mediaResult['ok']) {
                        throw new RuntimeException($mediaResult['error']);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $validationError = 'Today\'s entry already exists. Refresh the page.';
                } elseif ($e instanceof RuntimeException && $e->getMessage() !== '') {
                    $validationError = $e->getMessage();
                } else {
                    error_log('index note save: ' . $e->getMessage());
                    $validationError = 'Could not save your note. Please try again.';
                }
                $errorContext = 'create_first';
            }

            if ($validationError === null) {
                user_preferences_merge_save($pdo, $userId, [
                    'last_used_group_ids' => $selectedGroupIds,
                ]);
                header('Location: /index.php?saved=1');
                exit;
            }
        }
    }
}

$showSharedOnToday = !empty($prefs['today_show_shared']);

$yoursStmt = $pdo->prepare(
    <<<'SQL'
    SELECT n.id, n.entry_date, n.created_at
    FROM notes n
    WHERE n.user_id = ?
      AND n.entry_date = CURDATE()
    LIMIT 1
    SQL
);
$yoursStmt->execute([$userId]);
$yoursRow = $yoursStmt->fetch(PDO::FETCH_ASSOC);
$yoursToday = is_array($yoursRow) ? [$yoursRow] : [];

$todayPrimaryNote = $yoursToday[0] ?? null;
$todayPrimaryId = $todayPrimaryNote !== null ? (int) $todayPrimaryNote['id'] : 0;

$todayThoughts = [];
if ($todayPrimaryId > 0) {
    $thoughtMap = note_thoughts_grouped_by_note($pdo, [$todayPrimaryId], $userId);
    $todayThoughts = $thoughtMap[$todayPrimaryId] ?? [];
}

$todayThoughtReactionMap = thought_reactions_grouped_by_thought(
    $pdo,
    array_column($todayThoughts, 'id'),
    $userId
);

$todayNoteSharedWithGroup = $todayPrimaryId > 0 && note_is_shared_with_any_group($pdo, $todayPrimaryId);
$thoughtIdsForCommentFetch = [];
if ($todayNoteSharedWithGroup) {
    foreach ($todayThoughts as $thForComments) {
        if (empty($thForComments['is_private'])) {
            $thoughtIdsForCommentFetch[] = (int) $thForComments['id'];
        }
    }
}
$thoughtCommentsMap = thought_comments_grouped_by_thought($pdo, $thoughtIdsForCommentFetch);

$todayPrimarySharedRows = [];
if ($todayPrimaryId > 0) {
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
    $todayPrimarySharedRows = $sgStmt->fetchAll(PDO::FETCH_ASSOC);
}

$sharedToday = [];
if ($showSharedOnToday) {
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
          AND n.entry_date = CURDATE()
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
    $sharedStmt->execute([$userId, $userId, $userId]);
    $sharedToday = $sharedStmt->fetchAll(PDO::FETCH_ASSOC);
}

$todayNoteIds = array_merge(
    array_column($yoursToday, 'id'),
    array_column($sharedToday, 'id')
);
$todayPhotos = note_media_grouped_by_note($pdo, $todayNoteIds);

$sharedThoughtsByNote = $sharedToday !== []
    ? note_thoughts_grouped_by_note($pdo, array_column($sharedToday, 'id'), $userId)
    : [];

$sharedTodayThoughtIdsForReactions = [];
foreach ($sharedThoughtsByNote as $rows) {
    foreach ($rows as $tr) {
        $sharedTodayThoughtIdsForReactions[] = (int) $tr['id'];
    }
}
$sharedTodayReactionByThought = $sharedTodayThoughtIdsForReactions !== []
    ? thought_reactions_grouped_by_thought($pdo, $sharedTodayThoughtIdsForReactions, $userId)
    : [];

$sharedNoteSharedMap = [];
foreach (array_column($sharedToday, 'id') as $sharedNid) {
    $sharedNoteSharedMap[(int) $sharedNid] = note_is_shared_with_any_group($pdo, (int) $sharedNid);
}
$sharedThoughtIdsForCommentsFetch = [];
foreach ($sharedThoughtsByNote as $sharedThoughtsNid => $sharedThoughtRows) {
    if (!($sharedNoteSharedMap[(int) $sharedThoughtsNid] ?? false)) {
        continue;
    }
    foreach ($sharedThoughtRows as $sharedTr) {
        if (empty($sharedTr['is_private'])) {
            $sharedThoughtIdsForCommentsFetch[] = (int) $sharedTr['id'];
        }
    }
}
$sharedTodayThoughtCommentsMap = $sharedThoughtIdsForCommentsFetch !== []
    ? thought_comments_grouped_by_thought($pdo, $sharedThoughtIdsForCommentsFetch)
    : [];

$primaryPhotosList = $todayPhotos[$todayPrimaryId] ?? [];

$existingIdsForPrimary = array_column($primaryPhotosList, 'id');
$existingSetForPrimary = [];
foreach ($existingIdsForPrimary as $peid) {
    $existingSetForPrimary[(int) $peid] = true;
}
$delStickyCount = 0;
if ($editSticky) {
    $keepStickyCount = 0;
    foreach ($existingIdsForPrimary as $peid) {
        $pid = (int) $peid;
        if (isset($editStickyKeepMediaIds[$pid])) {
            $keepStickyCount++;
        }
    }
    $delStickyCount = count($existingIdsForPrimary) - $keepStickyCount;
}
$editRemainPhotoSlots = count($existingIdsForPrimary) - $delStickyCount;
if ($editRemainPhotoSlots < 0) {
    $editRemainPhotoSlots = 0;
}
$editMaxNewUploads = max(0, NOTE_MEDIA_MAX_FILES_PER_UPLOAD - $editRemainPhotoSlots);

$editGroupCheckedMap = [];
if ($todayPrimaryId > 0) {
    if ($editSticky) {
        $editGroupCheckedMap = $editStickyGroupIds;
    } else {
        foreach ($todayPrimarySharedRows as $row) {
            $editGroupCheckedMap[(int) $row['id']] = true;
        }
    }
}

$todayPrimarySharedNames = array_map(
    static fn (array $row): string => (string) $row['name'],
    $todayPrimarySharedRows
);

$canEditTodayNoteMeta = $todayPrimaryId > 0 && user_can_edit_note_today($pdo, $userId, $todayPrimaryId);

$pageTitle = 'Today';
$currentNav = 'today';
$savedFlash = isset($_GET['saved']);
$noteUpdatedFlash = isset($_GET['note_updated']);
$thoughtAddedFlash = isset($_GET['thought_added']);
$thoughtUpdatedFlash = isset($_GET['thought_updated']);
$thoughtDeletedFlash = isset($_GET['thought_deleted']);
$commentAddedFlash = isset($_GET['comment_added']);
$commentDeletedFlash = isset($_GET['comment_deleted']);
$commentErrFlash = isset($_GET['comment_err']);
$showEditInitially = $editSticky;

$showTopErrorBanner = $validationError !== null
    && ($errorContext === null || $errorContext === 'create_first');

require_once __DIR__ . '/header.php';
?>

            <?php if ($savedFlash): ?>
                <p class="flash" role="status">Saved.</p>
            <?php endif; ?>
            <?php if ($noteUpdatedFlash): ?>
                <p class="flash" role="status">Sharing and photos updated.</p>
            <?php endif; ?>
            <?php if ($thoughtAddedFlash): ?>
                <p class="flash" role="status">Thought added.</p>
            <?php endif; ?>
            <?php if ($thoughtUpdatedFlash): ?>
                <p class="flash" role="status">Thought updated.</p>
            <?php endif; ?>
            <?php if ($thoughtDeletedFlash): ?>
                <p class="flash" role="status">Thought removed.</p>
            <?php endif; ?>
            <?php if ($commentAddedFlash): ?>
                <p class="flash" role="status">Comment posted.</p>
            <?php endif; ?>
            <?php if ($commentDeletedFlash): ?>
                <p class="flash" role="status">Comment removed.</p>
            <?php endif; ?>
            <?php if ($commentErrFlash): ?>
                <p class="flash flash--error" role="alert">Couldn’t save that comment. Please try again.</p>
            <?php endif; ?>

            <?php if ($showTopErrorBanner): ?>
                <p class="flash flash--error" role="alert"><?= e((string) $validationError) ?></p>
            <?php endif; ?>

            <?php if ($todayPrimaryNote === null): ?>
            <form id="today-note-form" class="note-form" method="post" action="/index.php" enctype="multipart/form-data">
                <?php csrf_hidden_field(); ?>
                <input type="hidden" name="today_action" value="create_first">
                <label class="note-form__label" for="thought_body">What are you grateful for today?</label>
                <textarea
                    id="thought_body"
                    name="thought_body"
                    class="note-form__textarea"
                    rows="6"
                    placeholder="Write a few words…"
                ><?= e($formThoughtBodyValue) ?></textarea>

                <label class="share-check today-thought-private-check">
                    <input
                        type="checkbox"
                        name="thought_is_private"
                        value="1"
                        <?= $formThoughtPrivateSticky ? 'checked' : '' ?>
                    >
                    <span>Just for me</span>
                </label>
                <p class="share-fieldset__hint today-thought-private-hint">Private moments are never shown to people you share this day with.</p>

                <?php if (count($shareGroups) > 0): ?>
                    <fieldset class="share-fieldset">
                        <legend class="share-fieldset__legend">Share this day with…</legend>
                        <p class="share-fieldset__hint">Optional. Applies to your whole entry for today.</p>
                        <?php foreach ($shareGroups as $g): ?>
                            <label class="share-check">
                                <input
                                    type="checkbox"
                                    name="group_ids[]"
                                    value="<?= (int) $g['id'] ?>"
                                    <?= isset($preselectedGroupIds[(int) $g['id']]) ? 'checked' : '' ?>
                                >
                                <span><?= e($g['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <label class="note-form__label" for="today-photo-picker">Photos for today (optional)</label>
                <p class="share-fieldset__hint">JPEG or PNG. Images are resized on your device before upload.</p>
                <input
                    type="file"
                    id="today-photo-picker"
                    class="note-form__input"
                    multiple
                    accept="image/jpeg,image/png"
                    aria-describedby="today-photo-status"
                >
                <input
                    type="file"
                    name="photos[]"
                    id="today-photos-staged"
                    class="visually-hidden"
                    multiple
                    accept="image/jpeg,image/png"
                    tabindex="-1"
                    aria-hidden="true"
                >
                <p id="today-photo-status" class="today-photo-status" aria-live="polite"></p>
                <p id="today-photo-error" class="flash flash--error today-photo-error" role="alert" hidden></p>

                <button type="submit" class="btn btn--primary">Save</button>
            </form>
            <?php else: ?>
                <p class="today-quiet today-quiet--below-composer today-page-lede">
                    Your entry for today is below — thoughts and photos first, then sharing and actions.
                </p>
            <?php endif; ?>

            <section class="today-section" aria-labelledby="today-yours-heading">
                <h2 id="today-yours-heading" class="today-section__heading">Today — Yours</h2>
                <?php if ($todayPrimaryNote === null): ?>
                    <p class="today-quiet">No entry saved yet today.</p>
                <?php else: ?>
                    <ul class="today-yours-list">
                        <li class="today-yours-entry">
                            <?php if ($canEditTodayNoteMeta): ?>
                            <article class="today-daily-card" aria-labelledby="today-daily-card-label">
                                <p id="today-daily-card-label" class="today-daily-card__label">Today’s reflection</p>

                                <div class="today-daily-card__content">
                                    <ul class="today-thought-stream" aria-label="Today’s gratitude moments">
                                    <?php foreach ($todayThoughts as $th): ?>
                                        <?php
                                        $tid = (int) $th['id'];
                                        $canEditThought = user_can_edit_thought_today($pdo, $userId, $tid);
                                        $showThisThoughtEdit = ($errorContext === 'thought_edit' && $stickyThoughtEditId === $tid);
                                        $editBodyVal = $showThisThoughtEdit ? $stickyThoughtBodyValue : $th['body'];
                                        $editIsPrivate = $showThisThoughtEdit ? $stickyThoughtIsPrivate : !empty($th['is_private']);
                                        $thoughtReactions = $todayThoughtReactionMap[$tid] ?? [];
                                        $showThoughtComments = !$th['is_private'] && $todayNoteSharedWithGroup;
                                        $thoughtCommentsList = $thoughtCommentsMap[$tid] ?? [];
                                        $canPostThoughtComment = $showThoughtComments && thought_comment_post_window_open($th['created_at']);
                                        ?>
                                        <li class="today-thought" data-thought-id="<?= $tid ?>">
                                            <div class="today-thought-readonly" <?= $showThisThoughtEdit ? 'hidden' : '' ?>>
                                                <?php
                                                note_reading_render_thought_block(
                                                    $tid,
                                                    $th,
                                                    $thoughtReactions,
                                                    $showThoughtComments,
                                                    $thoughtCommentsList,
                                                    $canPostThoughtComment,
                                                    $userId,
                                                    '/index.php',
                                                    true,
                                                    true,
                                                    ['can_edit' => $canEditThought],
                                                );
                                                ?>
                                            </div>
                                            <?php if ($canEditThought): ?>
                                                <div class="today-thought-edit" <?= $showThisThoughtEdit ? '' : 'hidden' ?>>
                                                    <?php if ($showThisThoughtEdit && $validationError !== null): ?>
                                                        <p class="flash flash--error today-thought-edit-error" role="alert"><?= e((string) $validationError) ?></p>
                                                    <?php endif; ?>
                                                    <form class="note-form note-form--compact" method="post" action="/index.php">
                                                        <?php csrf_hidden_field(); ?>
                                                        <input type="hidden" name="today_action" value="update_thought">
                                                        <input type="hidden" name="thought_id" value="<?= $tid ?>">
                                                        <label class="visually-hidden" for="thought-edit-<?= $tid ?>">Edit thought</label>
                                                        <textarea
                                                            id="thought-edit-<?= $tid ?>"
                                                            name="thought_body"
                                                            class="note-form__textarea"
                                                            rows="5"
                                                            required
                                                        ><?= e($editBodyVal) ?></textarea>
                                                        <label class="share-check today-thought-private-check">
                                                            <input
                                                                type="checkbox"
                                                                name="thought_is_private"
                                                                value="1"
                                                                <?= $editIsPrivate ? 'checked' : '' ?>
                                                            >
                                                            <span>Just for me</span>
                                                        </label>
                                                        <p class="share-fieldset__hint today-thought-private-hint">Only you see private moments, even when this day is shared.</p>
                                                        <div class="today-thought-edit-actions">
                                                            <button type="submit" class="btn btn--primary">Save</button>
                                                            <button type="button" class="btn btn--ghost" data-thought-edit-cancel="<?= $tid ?>">Cancel</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>

                                    <?php if (!empty($todayPhotos[$todayPrimaryId])): ?>
                                        <div class="today-daily-card__gallery" role="group" aria-label="Photos for today">
                                            <ul class="today-note-photos today-note-photos--daily">
                                                <?php foreach ($todayPhotos[$todayPrimaryId] as $ph): ?>
                                                    <li class="today-note-photos__item">
                                                        <button
                                                            type="button"
                                                            class="photo-lightbox-trigger"
                                                            aria-haspopup="dialog"
                                                            aria-label="View photo larger"
                                                        >
                                                            <img
                                                                src="/media/note_photo.php?id=<?= (int) $ph['id'] ?>"
                                                                alt=""
                                                                class="today-note-photos__img"
                                                                loading="lazy"
                                                                width="<?= (int) $ph['width'] ?>"
                                                                height="<?= (int) $ph['height'] ?>"
                                                            >
                                                        </button>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div id="today-note-meta-readonly" class="today-daily-card__context today-note-meta-readonly" <?= $showEditInitially ? 'hidden' : '' ?>>
                                    <?php if (count($todayPrimarySharedNames) > 0): ?>
                                        <p class="today-daily-card__sharing today-yours-meta">
                                            Shared with <?= e(implode(', ', $todayPrimarySharedNames)) ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="today-daily-card__sharing today-yours-meta today-yours-meta--private">Private — not shared with groups</p>
                                    <?php endif; ?>

                                    <div class="today-daily-card__primary-actions">
                                        <button type="button" class="btn btn--primary" id="today-yours-edit-btn">Edit sharing &amp; photos</button>
                                    </div>
                                </div>

                                <div class="today-daily-card__secondary today-add-thought">
                                    <?php if ($errorContext === 'add_thought' && $validationError !== null): ?>
                                        <p class="flash flash--error" role="alert"><?= e((string) $validationError) ?></p>
                                    <?php endif; ?>
                                    <form class="note-form note-form--compact today-add-thought__form" method="post" action="/index.php">
                                        <?php csrf_hidden_field(); ?>
                                        <input type="hidden" name="today_action" value="add_thought">
                                        <input type="hidden" name="note_id" value="<?= $todayPrimaryId ?>">
                                        <label class="today-add-thought__caption" for="add-thought-body">Add another moment</label>
                                        <textarea
                                            id="add-thought-body"
                                            name="thought_body"
                                            class="note-form__textarea"
                                            rows="3"
                                            placeholder="A few more words…"
                                            ><?= e($addThoughtBodyValue) ?></textarea>
                                        <label class="share-check today-thought-private-check">
                                            <input
                                                type="checkbox"
                                                name="thought_is_private"
                                                value="1"
                                                <?= $addThoughtPrivateSticky ? 'checked' : '' ?>
                                            >
                                            <span>Just for me</span>
                                        </label>
                                        <p class="share-fieldset__hint today-thought-private-hint">Private moments are never shown to people you share this day with.</p>
                                        <button type="submit" class="btn btn--ghost today-add-thought__submit">Add moment</button>
                                    </form>
                                </div>
                            </article>

                                <div id="today-note-meta-edit" class="today-note-meta-edit-panel today-yours-panel today-yours-panel--edit" <?= $showEditInitially ? '' : 'hidden' ?>>
                                    <form id="today-edit-form" class="note-form note-form--compact" method="post" action="/index.php" enctype="multipart/form-data">
                                        <?php csrf_hidden_field(); ?>
                                        <input type="hidden" name="today_action" value="update_note">
                                        <input type="hidden" name="note_id" value="<?= $todayPrimaryId ?>">
                                        <p class="today-edit-note-hint">Sharing and photos apply to your whole entry for today.</p>
                                        <?php if ($errorContext === 'note_meta' && $validationError !== null): ?>
                                            <p class="flash flash--error today-edit-inline-error" role="alert"><?= e((string) $validationError) ?></p>
                                        <?php endif; ?>

                                        <?php if (count($shareGroups) > 0): ?>
                                            <fieldset class="share-fieldset">
                                                <legend class="share-fieldset__legend">Share with…</legend>
                                                <p class="share-fieldset__hint">Optional. Leave unchecked to keep this day private.</p>
                                                <?php foreach ($shareGroups as $g): ?>
                                                    <label class="share-check">
                                                        <input
                                                            type="checkbox"
                                                            name="group_ids[]"
                                                            value="<?= (int) $g['id'] ?>"
                                                            <?= isset($editGroupCheckedMap[(int) $g['id']]) ? 'checked' : '' ?>
                                                        >
                                                        <span><?= e($g['name']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                        <?php endif; ?>

                                        <?php if (count($primaryPhotosList) > 0): ?>
                                            <fieldset class="share-fieldset today-edit-media-fieldset">
                                                <legend class="share-fieldset__legend">Photos</legend>
                                                <p class="share-fieldset__hint">Uncheck photos you want to remove.</p>
                                                <ul class="today-edit-existing-photos">
                                                    <?php foreach ($primaryPhotosList as $eph): ?>
                                                        <?php $mid = (int) $eph['id']; ?>
                                                        <li class="today-edit-existing-photos__item">
                                                            <label class="today-edit-existing-photos__label">
                                                                <input
                                                                    type="checkbox"
                                                                    name="keep_media_ids[]"
                                                                    value="<?= $mid ?>"
                                                                    <?= (!$editSticky || isset($editStickyKeepMediaIds[$mid])) ? 'checked' : '' ?>
                                                                >
                                                                <img
                                                                    src="/media/note_photo.php?id=<?= $mid ?>"
                                                                    alt=""
                                                                    class="today-edit-existing-photos__thumb"
                                                                    loading="lazy"
                                                                    width="<?= (int) $eph['width'] ?>"
                                                                    height="<?= (int) $eph['height'] ?>"
                                                                >
                                                                <span class="today-edit-existing-photos__hint">Included</span>
                                                            </label>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </fieldset>
                                        <?php endif; ?>

                                        <?php if ($editMaxNewUploads > 0): ?>
                                            <label class="note-form__label" for="today-edit-photo-picker">Add photos (optional)</label>
                                            <p class="share-fieldset__hint">JPEG or PNG. Images are resized on your device before upload.</p>
                                            <input
                                                type="file"
                                                id="today-edit-photo-picker"
                                                class="note-form__input"
                                                multiple
                                                accept="image/jpeg,image/png"
                                                aria-describedby="today-edit-photo-status"
                                            >
                                            <input
                                                type="file"
                                                name="photos_edit[]"
                                                id="today-edit-photos-staged"
                                                class="visually-hidden"
                                                multiple
                                                accept="image/jpeg,image/png"
                                                tabindex="-1"
                                                aria-hidden="true"
                                            >
                                            <p id="today-edit-photo-status" class="today-photo-status" aria-live="polite"></p>
                                            <p id="today-edit-photo-error" class="flash flash--error today-photo-error" role="alert" hidden></p>
                                        <?php else: ?>
                                            <p class="share-fieldset__hint today-edit-photo-cap-hint">Uncheck a photo above to remove it and make room (limit <?= (int) NOTE_MEDIA_MAX_FILES_PER_UPLOAD ?> per note).</p>
                                        <?php endif; ?>

                                        <div class="today-edit-actions">
                                            <button type="submit" class="btn btn--primary">Save</button>
                                            <button type="button" class="btn btn--ghost" id="today-yours-cancel-edit">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php /* Should not happen for own today note */ ?>
                                <p class="today-quiet">This entry isn’t available.</p>
                            <?php endif; ?>
                        </li>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($showSharedOnToday): ?>
                <section class="today-section" aria-labelledby="today-shared-heading">
                    <h2 id="today-shared-heading" class="today-section__heading">Shared with you today</h2>
                    <?php if (count($sharedToday) === 0): ?>
                        <p class="today-quiet">Nothing shared yet today.</p>
                    <?php else: ?>
                        <ul class="notes-library">
                            <?php foreach ($sharedToday as $sn): ?>
                                <?php
                                $snId = (int) $sn['id'];
                                note_library_card_render(
                                    $sn,
                                    $userId,
                                    $sharedThoughtsByNote[$snId] ?? [],
                                    $todayPhotos[$snId] ?? [],
                                    $sharedTodayReactionByThought,
                                    $sharedTodayThoughtCommentsMap,
                                    $sharedNoteSharedMap[$snId] ?? false,
                                    '/index.php',
                                );
                                ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <script src="<?= e(asset_url('/note_resize.js')) ?>"></script>
            <script>
                (function () {
                    function bindTodayPhotoResize(opts) {
                        var form = document.getElementById(opts.formId);
                        var picker = document.getElementById(opts.pickerId);
                        var staged = document.getElementById(opts.stagedId);
                        var statusEl = document.getElementById(opts.statusId);
                        var errorEl = document.getElementById(opts.errorId);
                        var maxNew =
                            typeof opts.maxNewFiles === 'number' && opts.maxNewFiles >= 0
                                ? opts.maxNewFiles
                                : 10;

                        if (!form || !picker || !staged || !window.NotePhotoResize || !statusEl || !errorEl) {
                            return;
                        }

                        function clearPhotoError() {
                            errorEl.textContent = '';
                            errorEl.hidden = true;
                        }

                        function resetStagedPhotos() {
                            staged.files = new DataTransfer().files;
                        }

                        function showPhotoError(message) {
                            var fallback =
                                window.NotePhotoResize.USER_VISIBLE_FAILURE_MESSAGE ||
                                "We couldn't process this photo. Please try a different image.";
                            errorEl.textContent = message && message !== '' ? message : fallback;
                            errorEl.hidden = false;
                        }

                        picker.addEventListener('change', function () {
                            clearPhotoError();
                            if (!picker.files.length) {
                                statusEl.textContent = '';
                                return;
                            }
                            statusEl.textContent =
                                picker.files.length +
                                ' photo' +
                                (picker.files.length === 1 ? '' : 's') +
                                ' selected (will be resized before upload).';
                        });
                        form.addEventListener('submit', function (ev) {
                            if (!picker.files.length) {
                                return;
                            }
                            ev.preventDefault();
                            clearPhotoError();
                            resetStagedPhotos();
                            statusEl.textContent = 'Preparing photos…';
                            window.NotePhotoResize.resizeAll(picker.files, maxNew)
                                .then(function (blobs) {
                                    var dt = new DataTransfer();
                                    blobs.forEach(function (blob, i) {
                                        var ext = blob.type === 'image/png' ? 'png' : 'jpg';
                                        dt.items.add(
                                            new File([blob], 'photo-' + i + '.' + ext, { type: blob.type })
                                        );
                                    });
                                    staged.files = dt.files;
                                    picker.value = '';
                                    statusEl.textContent = '';
                                    clearPhotoError();
                                    form.submit();
                                })
                                .catch(function (err) {
                                    statusEl.textContent = '';
                                    resetStagedPhotos();
                                    showPhotoError(err && err.message ? err.message : '');
                                });
                        });
                    }

                    bindTodayPhotoResize({
                        formId: 'today-note-form',
                        pickerId: 'today-photo-picker',
                        stagedId: 'today-photos-staged',
                        statusId: 'today-photo-status',
                        errorId: 'today-photo-error',
                        maxNewFiles: 10,
                    });

                    bindTodayPhotoResize({
                        formId: 'today-edit-form',
                        pickerId: 'today-edit-photo-picker',
                        stagedId: 'today-edit-photos-staged',
                        statusId: 'today-edit-photo-status',
                        errorId: 'today-edit-photo-error',
                        maxNewFiles: <?= (int) $editMaxNewUploads ?>,
                    });

                    function closeAllThoughtEdits() {
                        document.querySelectorAll('.today-thought').forEach(function (li) {
                            var ro = li.querySelector('.today-thought-readonly');
                            var ed = li.querySelector('.today-thought-edit');
                            if (ro && ed) {
                                ro.hidden = false;
                                ed.hidden = true;
                            }
                        });
                    }

                    var readonlyPanel = document.getElementById('today-note-meta-readonly');
                    var editPanel = document.getElementById('today-note-meta-edit');
                    var editBtn = document.getElementById('today-yours-edit-btn');
                    var cancelBtn = document.getElementById('today-yours-cancel-edit');

                    if (editBtn && readonlyPanel && editPanel) {
                        editBtn.addEventListener('click', function () {
                            closeAllThoughtEdits();
                            readonlyPanel.hidden = true;
                            editPanel.hidden = false;
                        });
                    }

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function () {
                            window.location.href = '/index.php';
                        });
                    }

                    document.body.addEventListener('click', function (e) {
                        var openBtn = e.target.closest('[data-thought-edit-open]');
                        if (openBtn) {
                            e.preventDefault();
                            var metaEdit = document.getElementById('today-note-meta-edit');
                            var metaRo = document.getElementById('today-note-meta-readonly');
                            if (metaEdit && metaRo && metaEdit.hidden === false) {
                                metaEdit.hidden = true;
                                metaRo.hidden = false;
                            }
                            closeAllThoughtEdits();
                            var id = openBtn.getAttribute('data-thought-edit-open');
                            var li = openBtn.closest('.today-thought');
                            if (!li) {
                                return;
                            }
                            var ro = li.querySelector('.today-thought-readonly');
                            var ed = li.querySelector('.today-thought-edit');
                            if (ro && ed) {
                                ro.hidden = true;
                                ed.hidden = false;
                                var ta = ed.querySelector('textarea');
                                if (ta) {
                                    ta.focus();
                                }
                            }
                        }

                        var cancelThought = e.target.closest('[data-thought-edit-cancel]');
                        if (cancelThought) {
                            e.preventDefault();
                            window.location.href = '/index.php';
                        }
                    });
                })();
            </script>
            <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>
            <script src="<?= e(asset_url('/reactions/reactions.js')) ?>"></script>
            <script>
                (function () {
                    if (window.mountThoughtReactions) {
                        window.mountThoughtReactions({
                            csrfToken: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                        });
                    }
                })();
            </script>
            <div id="thought-reaction-picker" class="thought-reaction-picker-wrap" hidden></div>

<?php require_once __DIR__ . '/footer.php'; ?>
