<?php
/**
 * index.php — Today: primary gratitude composer, today’s own entries, shared-from-others today.
 *
 * Requires an authenticated session and writes notes for the current user.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/note_preview.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_access.php';

$userId = require_login();
$pdo = db();

$prefs = user_preferences_load($pdo, $userId);
$validationError = null;
$formContentValue = '';
$editSticky = false;
$editFormContent = '';
/** @var array<int, true> */
$editStickyGroupIds = [];
/** @var array<int, true> */
$editStickyDeleteMediaIds = [];

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

    $todayAction = isset($_POST['today_action']) ? (string) $_POST['today_action'] : 'create';

    if ($todayAction === 'update_today') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $editFormContent = is_string($_POST['content'] ?? null) ? (string) ($_POST['content'] ?? '') : '';
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

        $validated = validate_required_string($_POST['content'] ?? null, NOTE_CONTENT_MAX_LENGTH);
        if ($validationError === null && !$validated['ok']) {
            $validationError = $validated['error'];
        }

        if ($validationError === null) {
            foreach ($selectedGroupIds as $gid) {
                if (!user_is_group_member($pdo, $userId, $gid)) {
                    $validationError = 'Invalid group selection.';
                    break;
                }
            }
        }

        $rawDeletes = $_POST['delete_media_ids'] ?? [];
        if (!is_array($rawDeletes)) {
            $rawDeletes = [];
        }
        $requestedDeletes = [];
        foreach ($rawDeletes as $r) {
            if (is_numeric($r)) {
                $requestedDeletes[] = (int) $r;
            }
        }
        $requestedDeletes = array_values(array_unique(array_filter($requestedDeletes, static fn (int $id): bool => $id > 0)));

        $validDeletes = [];
        foreach ($requestedDeletes as $mid) {
            if (isset($existingIdSet[$mid])) {
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
            foreach ($selectedGroupIds as $gid) {
                $editStickyGroupIds[$gid] = true;
            }
            foreach ($requestedDeletes as $mid) {
                if (isset($existingIdSet[$mid])) {
                    $editStickyDeleteMediaIds[$mid] = true;
                }
            }
        }

        if ($validationError === null && $authorizedEdit) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE notes SET content = ? WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([$validated['value'], $noteId, $userId]);

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
                foreach ($selectedGroupIds as $gid) {
                    $editStickyGroupIds[$gid] = true;
                }
                foreach ($requestedDeletes as $mid) {
                    if (isset($existingIdSet[$mid])) {
                        $editStickyDeleteMediaIds[$mid] = true;
                    }
                }
            } else {
                user_preferences_merge_save($pdo, $userId, [
                    'last_used_group_ids' => $selectedGroupIds,
                ]);
                header('Location: /index.php?updated=1');
                exit;
            }
        }
    } else {
        $validated = validate_required_string($_POST['content'] ?? null, NOTE_CONTENT_MAX_LENGTH);
        $formContentValue = is_string($_POST['content'] ?? null) ? (string) ($_POST['content'] ?? '') : '';

        $selectedGroupIds = $parseTodayGroups($_POST);

        if (!$validated['ok']) {
            $validationError = $validated['error'];
        } else {
            foreach ($selectedGroupIds as $gid) {
                if (!user_is_group_member($pdo, $userId, $gid)) {
                    $validationError = 'Invalid group selection.';
                    break;
                }
            }
        }

        $uploadNorm = note_media_normalize_uploads_from_request();
        if ($validationError === null && !$uploadNorm['ok']) {
            $validationError = $uploadNorm['error'];
        }

        /** @var list<array{tmp:string,size:int}> $photoItems */
        $photoItems = ($uploadNorm['ok'] ?? false) ? $uploadNorm['items'] : [];

        if ($validationError === null) {
            $dup = $pdo->prepare(
                'SELECT id FROM notes WHERE user_id = ? AND DATE(created_at) = CURDATE() LIMIT 1'
            );
            $dup->execute([$userId]);
            if ($dup->fetchColumn()) {
                $validationError = 'You already saved today\'s entry. Use Edit below to change it.';
            }
        }

        if ($validationError === null) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO notes (user_id, content, visibility) VALUES (?, ?, ?)'
                );
                $stmt->execute([$userId, $validated['value'], 'private']);
                $noteId = (int) $pdo->lastInsertId();

                if ($noteId > 0 && count($selectedGroupIds) > 0) {
                    $link = $pdo->prepare(
                        'INSERT INTO note_groups (note_id, group_id) VALUES (?, ?)'
                    );
                    foreach ($selectedGroupIds as $gid) {
                        $link->execute([$noteId, $gid]);
                    }
                }

                if ($noteId > 0 && count($photoItems) > 0) {
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
                error_log('index note save: ' . $e->getMessage());
                $validationError = 'Could not save your note. Please try again.';
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
    SELECT n.id, n.content
    FROM notes n
    WHERE n.user_id = ?
      AND DATE(n.created_at) = CURDATE()
    ORDER BY n.created_at DESC, n.id DESC
    SQL
);
$yoursStmt->execute([$userId]);
$yoursToday = $yoursStmt->fetchAll(PDO::FETCH_ASSOC);

$todayPrimaryNote = $yoursToday[0] ?? null;
$todayPrimaryId = $todayPrimaryNote !== null ? (int) $todayPrimaryNote['id'] : 0;

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
    SELECT n.id,
           n.content,
           u.display_name AS author_name,
           (
               SELECT MIN(g.name)
               FROM note_groups ng
               INNER JOIN `groups` g ON g.id = ng.group_id
               INNER JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
               WHERE ng.note_id = n.id
           ) AS shared_via_group_name
    FROM notes n
    INNER JOIN users u ON u.id = n.user_id
    WHERE n.user_id <> ?
      AND DATE(n.created_at) = CURDATE()
      AND EXISTS (
          SELECT 1
          FROM note_groups ng2
          INNER JOIN group_members gm2 ON gm2.group_id = ng2.group_id AND gm2.user_id = ?
          WHERE ng2.note_id = n.id
      )
    ORDER BY n.created_at DESC, n.id DESC
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

/** @var array<int, list<string>> */
$yoursSharedNamesByNote = [];
if (count($yoursToday) > 0) {
    $yids = [];
    foreach ($yoursToday as $yr) {
        $yids[] = (int) $yr['id'];
    }
    $yids = array_values(array_unique(array_filter($yids, static fn (int $id): bool => $id > 0)));
    if ($yids !== []) {
        $placeholders = implode(',', array_fill(0, count($yids), '?'));
        $shareStmt = $pdo->prepare(
            <<<SQL
            SELECT ng.note_id, g.name
            FROM note_groups ng
            INNER JOIN `groups` g ON g.id = ng.group_id
            WHERE ng.note_id IN ($placeholders)
            ORDER BY ng.note_id ASC, g.name ASC
            SQL
        );
        $shareStmt->execute($yids);
        while ($srow = $shareStmt->fetch(PDO::FETCH_ASSOC)) {
            $nid = (int) $srow['note_id'];
            if (!isset($yoursSharedNamesByNote[$nid])) {
                $yoursSharedNamesByNote[$nid] = [];
            }
            $yoursSharedNamesByNote[$nid][] = (string) $srow['name'];
        }
    }
}

$primaryPhotosList = $todayPhotos[$todayPrimaryId] ?? [];

$existingIdsForPrimary = array_column($primaryPhotosList, 'id');
$existingSetForPrimary = [];
foreach ($existingIdsForPrimary as $peid) {
    $existingSetForPrimary[(int) $peid] = true;
}
$delStickyCount = 0;
foreach (array_keys($editStickyDeleteMediaIds) as $mid) {
    if (isset($existingSetForPrimary[$mid])) {
        $delStickyCount++;
    }
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

$editTextareaValue = $editSticky
    ? $editFormContent
    : (string) ($todayPrimaryNote['content'] ?? '');

$todayPrimarySharedNames = array_map(
    static fn (array $row): string => (string) $row['name'],
    $todayPrimarySharedRows
);

$pageTitle = 'Today';
$currentNav = 'today';
$savedFlash = isset($_GET['saved']);
$updatedFlash = isset($_GET['updated']);
$showEditInitially = $editSticky;

require_once __DIR__ . '/header.php';
?>

            <?php if ($savedFlash): ?>
                <p class="flash" role="status">Saved.</p>
            <?php endif; ?>
            <?php if ($updatedFlash): ?>
                <p class="flash" role="status">Updated.</p>
            <?php endif; ?>

            <?php if ($validationError !== null && !$editSticky): ?>
                <p class="flash flash--error" role="alert"><?= e($validationError) ?></p>
            <?php endif; ?>

            <?php if ($todayPrimaryNote === null): ?>
            <form id="today-note-form" class="note-form" method="post" action="/index.php" enctype="multipart/form-data">
                <?php csrf_hidden_field(); ?>
                <input type="hidden" name="today_action" value="create">
                <label class="note-form__label" for="content">What are you grateful for today?</label>
                <textarea
                    id="content"
                    name="content"
                    class="note-form__textarea"
                    rows="8"
                    placeholder="Write a few words…"
                ><?= e($formContentValue) ?></textarea>

                <?php if (count($shareGroups) > 0): ?>
                    <fieldset class="share-fieldset">
                        <legend class="share-fieldset__legend">Share with…</legend>
                        <p class="share-fieldset__hint">Optional. Leave unchecked to keep this note private.</p>
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

                <label class="note-form__label" for="today-photo-picker">Photos (optional)</label>
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
                <p class="today-quiet today-quiet--below-composer">Your gratitude for today is below. Select Edit to change it.</p>
            <?php endif; ?>

            <section class="today-section" aria-labelledby="today-yours-heading">
                <h2 id="today-yours-heading" class="today-section__heading">Today — Yours</h2>
                <?php if (count($yoursToday) === 0): ?>
                    <p class="today-quiet">No entry saved yet today.</p>
                <?php else: ?>
                    <ul class="today-yours-list">
                        <?php foreach ($yoursToday as $idx => $yn): ?>
                            <?php
                            $ynId = (int) $yn['id'];
                            $isPrimaryEditable = ($idx === 0 && $ynId === $todayPrimaryId && user_can_edit_note_today($pdo, $userId, $ynId));
                            ?>
                            <li class="today-yours-card<?= $isPrimaryEditable ? ' today-yours-card--editable' : '' ?>">
                                <?php if ($isPrimaryEditable): ?>
                                    <div id="today-yours-readonly" class="today-yours-panel" <?= $showEditInitially ? 'hidden' : '' ?>>
                                        <div class="today-yours-card__body"><?= nl2br(e((string) $yn['content'])) ?></div>
                                        <?php if (count($todayPrimarySharedNames) > 0): ?>
                                            <p class="today-yours-meta">
                                                Shared with <?= e(implode(', ', $todayPrimarySharedNames)) ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="today-yours-meta today-yours-meta--private">Private</p>
                                        <?php endif; ?>
                                        <?php if (!empty($todayPhotos[$ynId])): ?>
                                            <ul class="today-note-photos">
                                                <?php foreach ($todayPhotos[$ynId] as $ph): ?>
                                                    <li class="today-note-photos__item">
                                                        <img
                                                            src="/media/note_photo.php?id=<?= (int) $ph['id'] ?>"
                                                            alt=""
                                                            class="today-note-photos__img"
                                                            loading="lazy"
                                                            width="<?= (int) $ph['width'] ?>"
                                                            height="<?= (int) $ph['height'] ?>"
                                                        >
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <div class="today-yours-actions">
                                            <button type="button" class="btn btn--ghost" id="today-yours-edit-btn">Edit</button>
                                        </div>
                                    </div>

                                    <div id="today-yours-edit" class="today-yours-panel today-yours-panel--edit" <?= $showEditInitially ? '' : 'hidden' ?>>
                                        <form id="today-edit-form" class="note-form note-form--compact" method="post" action="/index.php" enctype="multipart/form-data">
                                            <?php csrf_hidden_field(); ?>
                                            <input type="hidden" name="today_action" value="update_today">
                                            <input type="hidden" name="note_id" value="<?= $todayPrimaryId ?>">
                                            <?php if ($validationError !== null): ?>
                                                <p class="flash flash--error today-edit-inline-error" role="alert"><?= e($validationError) ?></p>
                                            <?php endif; ?>
                                            <label class="note-form__label" for="today-edit-content">Your gratitude</label>
                                            <textarea
                                                id="today-edit-content"
                                                name="content"
                                                class="note-form__textarea"
                                                rows="8"
                                                required
                                            ><?= e($editTextareaValue) ?></textarea>

                                            <?php if (count($shareGroups) > 0): ?>
                                                <fieldset class="share-fieldset">
                                                    <legend class="share-fieldset__legend">Share with…</legend>
                                                    <p class="share-fieldset__hint">Optional. Leave unchecked to keep this note private.</p>
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
                                                    <p class="share-fieldset__hint">Uncheck to remove a photo when you save.</p>
                                                    <ul class="today-edit-existing-photos">
                                                        <?php foreach ($primaryPhotosList as $eph): ?>
                                                            <?php $mid = (int) $eph['id']; ?>
                                                            <li class="today-edit-existing-photos__item">
                                                                <label class="today-edit-existing-photos__label">
                                                                    <input
                                                                        type="checkbox"
                                                                        name="delete_media_ids[]"
                                                                        value="<?= $mid ?>"
                                                                        <?= isset($editStickyDeleteMediaIds[$mid]) ? 'checked' : '' ?>
                                                                    >
                                                                    <img
                                                                        src="/media/note_photo.php?id=<?= $mid ?>"
                                                                        alt=""
                                                                        class="today-edit-existing-photos__thumb"
                                                                        loading="lazy"
                                                                        width="<?= (int) $eph['width'] ?>"
                                                                        height="<?= (int) $eph['height'] ?>"
                                                                    >
                                                                    <span class="today-edit-existing-photos__hint">Remove</span>
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
                                                <p class="share-fieldset__hint today-edit-photo-cap-hint">Remove a photo above to add different ones (limit <?= (int) NOTE_MEDIA_MAX_FILES_PER_UPLOAD ?> per note).</p>
                                            <?php endif; ?>

                                            <div class="today-edit-actions">
                                                <button type="submit" class="btn btn--primary">Save</button>
                                                <button type="button" class="btn btn--ghost" id="today-yours-cancel-edit">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="today-yours-card__body"><?= nl2br(e((string) $yn['content'])) ?></div>
                                    <?php $extraNames = $yoursSharedNamesByNote[$ynId] ?? []; ?>
                                    <?php if (count($extraNames) > 0): ?>
                                        <p class="today-yours-meta">
                                            Shared with <?= e(implode(', ', $extraNames)) ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="today-yours-meta today-yours-meta--private">Private</p>
                                    <?php endif; ?>
                                    <?php if (!empty($todayPhotos[$ynId])): ?>
                                        <ul class="today-note-photos">
                                            <?php foreach ($todayPhotos[$ynId] as $ph): ?>
                                                <li class="today-note-photos__item">
                                                    <img
                                                        src="/media/note_photo.php?id=<?= (int) $ph['id'] ?>"
                                                        alt=""
                                                        class="today-note-photos__img"
                                                        loading="lazy"
                                                        width="<?= (int) $ph['width'] ?>"
                                                        height="<?= (int) $ph['height'] ?>"
                                                    >
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($showSharedOnToday): ?>
                <section class="today-section" aria-labelledby="today-shared-heading">
                    <h2 id="today-shared-heading" class="today-section__heading">Shared with you today</h2>
                    <?php if (count($sharedToday) === 0): ?>
                        <p class="today-quiet">Nothing shared yet today.</p>
                    <?php else: ?>
                        <ul class="today-shared-list">
                            <?php foreach ($sharedToday as $sn): ?>
                                <?php
                                $authorLabel = trim((string) ($sn['author_name'] ?? ''));
                                if ($authorLabel === '') {
                                    $authorLabel = 'Someone';
                                }
                                $groupLabel = trim((string) ($sn['shared_via_group_name'] ?? ''));
                                if ($groupLabel === '') {
                                    $groupLabel = 'your group';
                                }
                                $preview = note_plain_preview((string) $sn['content'], 160);
                                ?>
                                <li class="today-shared-card">
                                    <p class="today-shared-card__meta">
                                        <span class="today-shared-card__author"><?= e($authorLabel) ?></span>
                                        <span class="today-shared-card__context"> · Shared in <?= e($groupLabel) ?></span>
                                    </p>
                                    <p class="today-shared-card__preview"><?= e($preview) ?></p>
                                    <?php
                                    $snId = (int) $sn['id'];
                                    if (!empty($todayPhotos[$snId])):
                                        ?>
                                        <ul class="today-note-photos today-note-photos--shared">
                                            <?php foreach ($todayPhotos[$snId] as $ph): ?>
                                                <li class="today-note-photos__item">
                                                    <img
                                                        src="/media/note_photo.php?id=<?= (int) $ph['id'] ?>"
                                                        alt=""
                                                        class="today-note-photos__img"
                                                        loading="lazy"
                                                        width="<?= (int) $ph['width'] ?>"
                                                        height="<?= (int) $ph['height'] ?>"
                                                    >
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <script src="/note_resize.js"></script>
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

                    var readonlyPanel = document.getElementById('today-yours-readonly');
                    var editPanel = document.getElementById('today-yours-edit');
                    var editBtn = document.getElementById('today-yours-edit-btn');
                    var cancelBtn = document.getElementById('today-yours-cancel-edit');

                    if (editBtn && readonlyPanel && editPanel) {
                        editBtn.addEventListener('click', function () {
                            readonlyPanel.hidden = true;
                            editPanel.hidden = false;
                            var ta = document.getElementById('today-edit-content');
                            if (ta) {
                                ta.focus();
                            }
                        });
                    }

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function () {
                            window.location.href = '/index.php';
                        });
                    }
                })();
            </script>

<?php require_once __DIR__ . '/footer.php'; ?>
