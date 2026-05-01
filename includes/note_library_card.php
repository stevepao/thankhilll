<?php
/**
 * includes/note_library_card.php — Full inline note reading surface (Notes list + Today shared).
 *
 * Text is always expanded; only image thumbnails open the lightbox. Optional permalink to note.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/escape.php';
require_once __DIR__ . '/note_reading_thoughts.php';

/**
 * @param array{id:int,entry_date:string,user_id:int,author_name?:string,shared_group_names?:string|null} $noteRow
 * @param list<array{id:int,note_id:int,body:string,created_at:string,is_private:bool}> $thoughtRows
 * @param list<array{id:int,width:int,height:int}> $thumbs
 * @param array<int, list<array{emoji:string,count:int,reacted_by_me:bool}>> $reactionByThoughtMap
 * @param array<int, list<array{id:int,user_id:int,body:string,created_at:string,display_name:string}>> $thoughtCommentsMap
 */
function note_library_card_render(
    array $noteRow,
    int $viewerUserId,
    array $thoughtRows,
    array $thumbs,
    array $reactionByThoughtMap,
    array $thoughtCommentsMap,
    bool $noteSharedWithGroup,
    string $commentRedirectTarget,
): void {
    $nid = (int) $noteRow['id'];
    $authorId = (int) $noteRow['user_id'];
    $isMine = $authorId === $viewerUserId;
    $authorLabel = trim((string) ($noteRow['author_name'] ?? ''));
    if ($authorLabel === '') {
        $authorLabel = 'Someone';
    }
    $groupsLabel = trim((string) ($noteRow['shared_group_names'] ?? ''));
    $ts = strtotime((string) $noteRow['entry_date']);
    $dateLabel = $ts ? date('M j, Y', $ts) : '';
    ?>
                        <li class="notes-library__card">
                            <?php if (count($thumbs) > 0): ?>
                                <ul class="today-note-photos today-note-photos--notes notes-library__card-photos" aria-label="Note photos — tap a thumbnail to enlarge">
                                    <?php foreach ($thumbs as $thumb): ?>
                                        <li class="today-note-photos__item">
                                            <button
                                                type="button"
                                                class="photo-lightbox-trigger"
                                                aria-haspopup="dialog"
                                                aria-label="View photo larger"
                                            >
                                                <img
                                                    src="/media/note_photo.php?id=<?= (int) $thumb['id'] ?>"
                                                    alt=""
                                                    class="today-note-photos__img"
                                                    loading="lazy"
                                                    width="<?= (int) $thumb['width'] ?>"
                                                    height="<?= (int) $thumb['height'] ?>"
                                                >
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <article class="notes-library__article">
                                <header class="notes-library__header">
                                    <time
                                        class="notes-library__date"
                                        datetime="<?= e((string) $noteRow['entry_date']) ?>"
                                    ><?= e($dateLabel) ?></time>
                                    <?php if (!$isMine): ?>
                                        <p class="notes-library__author"><?= e($authorLabel) ?></p>
                                    <?php endif; ?>
                                    <?php if ($groupsLabel !== ''): ?>
                                        <p class="notes-library__groups">Shared in <?= e($groupsLabel) ?></p>
                                    <?php endif; ?>
                                </header>
                                <?php
                                note_reading_render_thoughts_list(
                                    $viewerUserId,
                                    $thoughtRows,
                                    $reactionByThoughtMap,
                                    $thoughtCommentsMap,
                                    $noteSharedWithGroup,
                                    $isMine,
                                    $commentRedirectTarget,
                                    false,
                                );
                                ?>
                                <p class="notes-library__permalink">
                                    <a href="/note.php?id=<?= $nid ?>">Permalink</a>
                                </p>
                            </article>
                        </li>
    <?php
}
