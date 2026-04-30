<?php
/**
 * index.php — "Today" screen: write today’s gratitude note and save to the database.
 *
 * Single implicit user for now (no user_id column). Uses POST + redirect to avoid duplicate submits.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content !== '') {
        $stmt = db()->prepare('INSERT INTO notes (content) VALUES (?)');
        $stmt->execute([$content]);
    }
    header('Location: index.php?saved=1');
    exit;
}

$pageTitle = 'Today';
$currentNav = 'today';
$saved = isset($_GET['saved']);

require_once __DIR__ . '/header.php';
?>

            <?php if ($saved): ?>
                <p class="flash" role="status">Saved.</p>
            <?php endif; ?>

            <form class="note-form" method="post" action="index.php">
                <label class="note-form__label" for="content">What are you grateful for today?</label>
                <textarea
                    id="content"
                    name="content"
                    class="note-form__textarea"
                    rows="8"
                    placeholder="Write a few words…"
                    required
                ></textarea>
                <button type="submit" class="btn btn--primary">Save</button>
            </form>

<?php require_once __DIR__ . '/footer.php'; ?>
