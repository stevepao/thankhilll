<?php
/**
 * note.php — Single note view (browse-only) with photos.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/note_access.php';
require_once __DIR__ . '/includes/note_media.php';

$userId = require_login();
$pdo = db();

$noteId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($noteId <= 0) {
    header('Location: /notes.php');
    exit;
}

if (!user_can_view_note($pdo, $userId, $noteId)) {
    http_response_code(404);
    $pageTitle = 'Note';
    $currentNav = 'notes';
    require_once __DIR__ . '/header.php';
    echo '<p class="notes-empty">This note is not available.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT n.id, n.content, n.created_at, n.user_id, u.display_name AS author_name
     FROM notes n
     LEFT JOIN users u ON u.id = n.user_id
     WHERE n.id = ?
     LIMIT 1'
);
$stmt->execute([$noteId]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($note)) {
    header('Location: /notes.php');
    exit;
}

$media = note_media_for_note($pdo, $noteId);
$isMine = ((int) $note['user_id']) === $userId;
$authorLabel = trim((string) ($note['author_name'] ?? ''));
if ($authorLabel === '') {
    $authorLabel = 'Someone';
}

$ts = strtotime((string) $note['created_at']);
$dateLabel = $ts ? date('M j, Y', $ts) : '';

$pageTitle = 'Note';
$currentNav = 'notes';

require_once __DIR__ . '/header.php';
?>

            <p class="sub-nav">
                <a href="/notes.php">← Notes</a>
            </p>

            <article class="note-detail">
                <time class="note-detail__date" datetime="<?= e((string) $note['created_at']) ?>"><?= e($dateLabel) ?></time>
                <?php if (!$isMine): ?>
                    <p class="note-detail__author"><?= e($authorLabel) ?></p>
                <?php endif; ?>
                <div class="note-detail__body"><?= nl2br(e((string) $note['content'])) ?></div>

                <?php if (count($media) > 0): ?>
                    <ul class="note-detail__photos">
                        <?php foreach ($media as $m): ?>
                            <li class="note-detail__photo-item">
                                <img
                                    src="/media/note_photo.php?id=<?= (int) $m['id'] ?>"
                                    alt=""
                                    class="note-detail__photo"
                                    loading="lazy"
                                    width="<?= (int) $m['width'] ?>"
                                    height="<?= (int) $m['height'] ?>"
                                >
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

<?php require_once __DIR__ . '/footer.php'; ?>
