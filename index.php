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

$userId = require_login();
$pdo = db();

$prefs = user_preferences_load($pdo, $userId);
$validationError = null;
$formContentValue = '';
$shareGroups = groups_for_user_with_counts($pdo, $userId);

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

    $validated = validate_required_string($_POST['content'] ?? null, NOTE_CONTENT_MAX_LENGTH);
    $formContentValue = is_string($_POST['content'] ?? null) ? (string) ($_POST['content'] ?? '') : '';

    $rawGroupIds = $_POST['group_ids'] ?? [];
    if (!is_array($rawGroupIds)) {
        $rawGroupIds = [];
    }
    $selectedGroupIds = [];
    foreach ($rawGroupIds as $gidRaw) {
        if (is_numeric($gidRaw)) {
            $selectedGroupIds[] = (int) $gidRaw;
        }
    }
    $selectedGroupIds = array_values(array_unique(array_filter($selectedGroupIds, static fn (int $id): bool => $id > 0)));

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

$pageTitle = 'Today';
$currentNav = 'today';
$saved = isset($_GET['saved']);

require_once __DIR__ . '/header.php';
?>

            <?php if ($saved): ?>
                <p class="flash" role="status">Saved.</p>
            <?php endif; ?>

            <?php if ($validationError !== null): ?>
                <p class="flash flash--error" role="alert"><?= e($validationError) ?></p>
            <?php endif; ?>

            <form id="today-note-form" class="note-form" method="post" action="/index.php" enctype="multipart/form-data">
                <?php csrf_hidden_field(); ?>
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

                <button type="submit" class="btn btn--primary">Save</button>
            </form>

            <section class="today-section" aria-labelledby="today-yours-heading">
                <h2 id="today-yours-heading" class="today-section__heading">Today — Yours</h2>
                <?php if (count($yoursToday) === 0): ?>
                    <p class="today-quiet">No entry saved yet today.</p>
                <?php else: ?>
                    <ul class="today-yours-list">
                        <?php foreach ($yoursToday as $yn): ?>
                            <li class="today-yours-card">
                                <div class="today-yours-card__body"><?= nl2br(e((string) $yn['content'])) ?></div>
                                <?php
                                $ynId = (int) $yn['id'];
                                if (!empty($todayPhotos[$ynId])):
                                    ?>
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
                    var form = document.getElementById('today-note-form');
                    var picker = document.getElementById('today-photo-picker');
                    var staged = document.getElementById('today-photos-staged');
                    var statusEl = document.getElementById('today-photo-status');
                    if (!form || !picker || !staged || !window.NotePhotoResize) {
                        return;
                    }
                    picker.addEventListener('change', function () {
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
                        statusEl.textContent = 'Preparing photos…';
                        window.NotePhotoResize.resizeAll(picker.files, window.NotePhotoResize.maxFiles)
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
                                form.submit();
                            })
                            .catch(function (err) {
                                statusEl.textContent = '';
                                window.alert(err && err.message ? err.message : 'Could not process photos.');
                            });
                    });
                })();
            </script>

<?php require_once __DIR__ . '/footer.php'; ?>
