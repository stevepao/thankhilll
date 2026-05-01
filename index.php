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

$userId = require_login();

$validationError = null;
$formContentValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $validated = validate_required_string($_POST['content'] ?? null, NOTE_CONTENT_MAX_LENGTH);
    $formContentValue = is_string($_POST['content'] ?? null) ? (string) ($_POST['content'] ?? '') : '';

    if (!$validated['ok']) {
        $validationError = $validated['error'];
    } else {
        $stmt = db()->prepare('INSERT INTO notes (user_id, content, visibility) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $validated['value'], 'private']);
        header('Location: /index.php?saved=1');
        exit;
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
                <button type="submit" class="btn btn--primary">Save</button>
            </form>

<?php require_once __DIR__ . '/footer.php'; ?>
