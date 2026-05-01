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

$userId = require_login();
$pdo = db();

$validationError = null;
$formContentValue = '';
$shareGroups = groups_for_user_with_counts($pdo, $userId);

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

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('index note save: ' . $e->getMessage());
            $validationError = 'Could not save your note. Please try again.';
        }

        if ($validationError === null) {
            header('Location: /index.php?saved=1');
            exit;
        }
    }
}

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

            <form class="note-form" method="post" action="/index.php">
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
                                >
                                <span><?= e($g['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

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
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

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
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

<?php require_once __DIR__ . '/footer.php'; ?>
