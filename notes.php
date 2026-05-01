<?php
/**
 * notes.php — Lists all saved notes, newest first (simple cards).
 *
 * Requires login and only shows notes for the current user.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$userId = require_login();

$stmt = db()->prepare(
    'SELECT id, content, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC, id DESC'
);
$stmt->execute([$userId]);
$notes = $stmt->fetchAll();

$pageTitle = 'Notes';
$currentNav = 'notes';

require_once __DIR__ . '/header.php';
?>

            <?php if (count($notes) === 0): ?>
                <p class="empty-state">No notes yet. Add something on <a href="/index.php">Today</a>.</p>
            <?php else: ?>
                <ul class="note-list">
                    <?php foreach ($notes as $note): ?>
                        <?php
                        $ts = strtotime((string) $note['created_at']);
                        $when = $ts ? date('M j, Y · g:i A', $ts) : e((string) $note['created_at']);
                        ?>
                        <li class="note-card">
                            <time class="note-card__time" datetime="<?= e((string) $note['created_at']) ?>">
                                <?= e($when) ?>
                            </time>
                            <div class="note-card__body"><?= nl2br(e((string) $note['content'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
