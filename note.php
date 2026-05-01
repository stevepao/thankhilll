<?php
/**
 * note.php — Single note view (browse-only) with photos.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/note_access.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_thoughts.php';

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
    <<<'SQL'
    SELECT n.id, n.entry_date, n.created_at, n.updated_at, n.user_id, u.display_name AS author_name
    FROM notes n
    LEFT JOIN users u ON u.id = n.user_id
    WHERE n.id = ?
    LIMIT 1
    SQL
);
$stmt->execute([$noteId]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($note)) {
    header('Location: /notes.php');
    exit;
}

$thoughtMap = note_thoughts_grouped_by_note($pdo, [$noteId], $userId);
$thoughts = $thoughtMap[$noteId] ?? [];

$media = note_media_for_note($pdo, $noteId);
$isMine = ((int) $note['user_id']) === $userId;
$authorLabel = trim((string) ($note['author_name'] ?? ''));
if ($authorLabel === '') {
    $authorLabel = 'Someone';
}

$ts = strtotime((string) $note['entry_date']);
$dateLabel = $ts ? date('M j, Y', $ts) : '';

$pageTitle = 'Note';
$currentNav = 'notes';

require_once __DIR__ . '/header.php';
?>

            <p class="sub-nav">
                <a href="/notes.php">← Notes</a>
            </p>

            <article class="note-detail">
                <time class="note-detail__date" datetime="<?= e((string) $note['entry_date']) ?>"><?= e($dateLabel) ?></time>
                <?php if (!$isMine): ?>
                    <p class="note-detail__author"><?= e($authorLabel) ?></p>
                <?php endif; ?>

                <ul class="note-detail__thoughts">
                    <?php foreach ($thoughts as $th): ?>
                        <li class="note-detail__thought">
                            <p class="note-detail__thought-body">
                                <?php if ($isMine && !empty($th['is_private'])): ?>
                                    <span class="note-detail__thought-private-wrap" role="img" aria-label="Private — only visible to you">
                                        <span class="note-detail__thought-private" aria-hidden="true">🔒</span>
                                    </span>
                                <?php endif; ?>
                                <?= nl2br(e($th['body'])) ?>
                            </p>
                            <p class="note-detail__thought-time"><?= e(note_thought_time_label($th['created_at'])) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>

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
