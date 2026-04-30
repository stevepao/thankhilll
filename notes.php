<?php
/**
 * notes.php — Lists all saved notes, newest first (simple cards).
 *
 * Read-only listing; editing/deleting can be added later.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$stmt = db()->query(
    'SELECT id, content, created_at FROM notes ORDER BY created_at DESC, id DESC'
);
$notes = $stmt->fetchAll();

$pageTitle = 'Notes';
$currentNav = 'notes';

require_once __DIR__ . '/header.php';
?>

            <?php if (count($notes) === 0): ?>
                <p class="empty-state">No notes yet. Add something on <a href="index.php">Today</a>.</p>
            <?php else: ?>
                <ul class="note-list">
                    <?php foreach ($notes as $note): ?>
                        <?php
                        $ts = strtotime((string) $note['created_at']);
                        $when = $ts ? date('M j, Y · g:i A', $ts) : htmlspecialchars((string) $note['created_at'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <li class="note-card">
                            <time class="note-card__time" datetime="<?= htmlspecialchars((string) $note['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($when, ENT_QUOTES, 'UTF-8') ?>
                            </time>
                            <div class="note-card__body"><?= nl2br(htmlspecialchars((string) $note['content'], ENT_QUOTES, 'UTF-8')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
