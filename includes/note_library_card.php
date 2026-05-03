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
 * @param list<array{id:int,note_id:int,body:string,created_at:string,is_private:bool,entry_date:string}> $thoughtRows
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
    string $viewerTz,
    bool $notesTailwindUi = false,
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
    $tw = $notesTailwindUi;
    $cardLi = $tw
        ? 'notes-library__card tn-rounded-xl tn-bg-tn-surface tn-shadow-tn tn-p-5 md:tn-p-6 tn-space-y-4'
        : 'notes-library__card';
    $photoUl = $tw
        ? 'today-note-photos today-note-photos--notes notes-library__card-photos tn-grid tn-grid-cols-2 md:tn-grid-cols-3 tn-gap-2 tn-list-none tn-m-0 tn-p-0 tn-w-full'
        : 'today-note-photos today-note-photos--notes notes-library__card-photos';
    $photoLi = $tw
        ? 'today-note-photos__item tn-aspect-square tn-overflow-hidden tn-rounded-lg tn-min-w-0 tn-m-0'
        : 'today-note-photos__item';
    $photoBtn = $tw
        ? 'photo-lightbox-trigger tn-block tn-h-full tn-w-full tn-p-0 tn-m-0 tn-overflow-hidden tn-rounded-lg'
        : 'photo-lightbox-trigger';
    $photoImg = $tw
        ? 'today-note-photos__img tn-h-full tn-w-full tn-object-cover tn-rounded-lg tn-max-h-none tn-border-0 tn-bg-stone-100'
        : 'today-note-photos__img';
    $articleCls = $tw ? 'notes-library__article tn-space-y-4' : 'notes-library__article';
    $headerCls = $tw ? 'notes-library__header tn-space-y-1 tn-mb-0' : 'notes-library__header';
    $dateCls = $tw
        ? 'notes-library__date tn-block tn-text-xs tn-font-semibold tn-text-tn-muted'
        : 'notes-library__date';
    $authorCls = $tw
        ? 'notes-library__author tn-text-sm tn-font-semibold tn-text-tn-ink tn-m-0 tn-leading-snug'
        : 'notes-library__author';
    $groupsCls = $tw
        ? 'notes-library__groups tn-text-xs tn-text-tn-muted tn-m-0 tn-leading-snug'
        : 'notes-library__groups';
    $permalinkP = $tw ? 'notes-library__permalink tn-m-0 tn-pt-1' : 'notes-library__permalink';
    $permalinkA = $tw ? ' tn-text-tn-accent tn-font-medium tn-no-underline hover:tn-underline' : '';
    ?>
                        <li class="<?= e($cardLi) ?>">
                            <?php if (count($thumbs) > 0): ?>
                                <ul class="<?= e($photoUl) ?>" aria-label="Note photos — tap a thumbnail to enlarge">
                                    <?php foreach ($thumbs as $thumb): ?>
                                        <li class="<?= e($photoLi) ?>">
                                            <button
                                                type="button"
                                                class="<?= e($photoBtn) ?>"
                                                aria-haspopup="dialog"
                                                aria-label="View photo larger"
                                            >
                                                <img
                                                    src="/media/note_photo.php?id=<?= (int) $thumb['id'] ?>"
                                                    alt=""
                                                    class="<?= e($photoImg) ?>"
                                                    loading="lazy"
                                                    width="<?= (int) $thumb['width'] ?>"
                                                    height="<?= (int) $thumb['height'] ?>"
                                                >
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <article class="<?= e($articleCls) ?>">
                                <header class="<?= e($headerCls) ?>">
                                    <time
                                        class="<?= e($dateCls) ?>"
                                        datetime="<?= e((string) $noteRow['entry_date']) ?>"
                                    ><?= e($dateLabel) ?></time>
                                    <?php if (!$isMine): ?>
                                        <p class="<?= e($authorCls) ?>"><?= e($authorLabel) ?></p>
                                    <?php endif; ?>
                                    <?php if ($groupsLabel !== ''): ?>
                                        <p class="<?= e($groupsCls) ?>">Shared in <?= e($groupsLabel) ?></p>
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
                                    $viewerTz,
                                    false,
                                    $notesTailwindUi,
                                );
                                ?>
                                <p class="<?= e($permalinkP) ?>">
                                    <a href="/note.php?id=<?= $nid ?>" class="<?= e(trim($permalinkA)) ?>">Permalink</a>
                                </p>
                            </article>
                        </li>
    <?php
}
