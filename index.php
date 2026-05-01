<?php
/**
 * index.php — "Today" screen: write today’s gratitude note and save to the database.
 *
 * Requires an authenticated session and writes notes for the current user.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/group_helpers.php';

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

<?php require_once __DIR__ . '/footer.php'; ?>
