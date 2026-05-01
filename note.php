<?php
/**
 * note.php — Single note view (browse-only) with photos.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/note_access.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_thoughts.php';
require_once __DIR__ . '/includes/thought_reactions.php';
require_once __DIR__ . '/includes/thought_comments.php';
require_once __DIR__ . '/includes/note_reading_thoughts.php';
require_once __DIR__ . '/includes/user_timezone.php';

$userId = require_login();
$pdo = db();
$viewerTz = user_timezone_get($pdo, $userId);

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
$thoughtReactionMap = thought_reactions_grouped_by_thought($pdo, array_column($thoughts, 'id'), $userId);

$noteSharedWithGroup = note_is_shared_with_any_group($pdo, $noteId);
$thoughtIdsForCommentFetch = [];
if ($noteSharedWithGroup) {
    foreach ($thoughts as $thCommentElig) {
        if (empty($thCommentElig['is_private'])) {
            $thoughtIdsForCommentFetch[] = (int) $thCommentElig['id'];
        }
    }
}
$thoughtCommentsMap = thought_comments_grouped_by_thought($pdo, $thoughtIdsForCommentFetch);

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

$commentAddedFlash = isset($_GET['comment_added']);
$commentDeletedFlash = isset($_GET['comment_deleted']);
$commentErrFlash = isset($_GET['comment_err']);

require_once __DIR__ . '/header.php';
?>

            <p class="sub-nav">
                <a href="/notes.php">← Notes</a>
            </p>

            <?php if ($commentAddedFlash): ?>
                <p class="flash" role="status">Comment posted.</p>
            <?php endif; ?>
            <?php if ($commentDeletedFlash): ?>
                <p class="flash" role="status">Comment removed.</p>
            <?php endif; ?>
            <?php if ($commentErrFlash): ?>
                <p class="flash flash--error" role="alert">Couldn’t save that comment. Please try again.</p>
            <?php endif; ?>

            <article class="note-detail">
                <time class="note-detail__date" datetime="<?= e((string) $note['entry_date']) ?>"><?= e($dateLabel) ?></time>
                <?php if (!$isMine): ?>
                    <p class="note-detail__author"><?= e($authorLabel) ?></p>
                <?php endif; ?>

                <?php
                note_reading_render_thoughts_list(
                    $userId,
                    $thoughts,
                    $thoughtReactionMap,
                    $thoughtCommentsMap,
                    $noteSharedWithGroup,
                    $isMine,
                    '/note.php?id=' . $noteId,
                    $viewerTz,
                    false,
                );
                ?>

                <?php if (count($media) > 0): ?>
                    <ul class="note-detail__photos">
                        <?php foreach ($media as $m): ?>
                            <li class="note-detail__photo-item">
                                <button
                                    type="button"
                                    class="photo-lightbox-trigger note-detail__photo-trigger"
                                    aria-haspopup="dialog"
                                    aria-label="View photo larger"
                                >
                                    <img
                                        src="/media/note_photo.php?id=<?= (int) $m['id'] ?>"
                                        alt=""
                                        class="note-detail__photo"
                                        loading="lazy"
                                        width="<?= (int) $m['width'] ?>"
                                        height="<?= (int) $m['height'] ?>"
                                    >
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>
            <script src="<?= e(asset_url('/reactions/reactions.js')) ?>"></script>
            <script>
                (function () {
                    if (window.mountThoughtReactions) {
                        window.mountThoughtReactions({
                            csrfToken: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                        });
                    }
                })();
            </script>
            <div id="thought-reaction-picker" class="thought-reaction-picker-wrap" hidden></div>

<?php require_once __DIR__ . '/footer.php'; ?>
