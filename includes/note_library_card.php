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
        ? 'notes-library__card tn-th-item tn-space-y-3'
        : 'notes-library__card';
    $photoUl = $tw
        ? 'today-note-photos today-note-photos--notes notes-library__card-photos tn-grid tn-grid-cols-2 md:tn-grid-cols-3 tn-gap-2 tn-list-none tn-m-0 tn-p-0 tn-w-full'
        : 'today-note-photos today-note-photos--notes notes-library__card-photos';
    $photoLi = $tw
        ? 'today-note-photos__item tn-h-[5.25rem] sm:tn-h-28 tn-overflow-hidden tn-rounded-lg tn-min-w-0 tn-m-0'
        : 'today-note-photos__item';
    $photoBtn = $tw
        ? 'photo-lightbox-trigger tn-flex tn-h-full tn-w-full tn-items-center tn-justify-center tn-p-0 tn-m-0 tn-overflow-hidden tn-rounded-lg'
        : 'photo-lightbox-trigger';
    $photoImg = $tw
        ? 'today-note-photos__img tn-h-full tn-w-full tn-min-h-0 tn-object-cover tn-rounded-lg tn-border-0 tn-bg-slate-100'
        : 'today-note-photos__img';
    $articleCls = $tw ? 'notes-library__article tn-space-y-3' : 'notes-library__article';
    $postHeadCls = $tw
        ? 'notes-library__header tn-th-item-head'
        : 'notes-library__header';
    $headerCls = $tw ? 'notes-library__header tn-space-y-2 tn-mb-0 tn-pb-0' : 'notes-library__header';
    $dateCls = 'notes-library__date';
    $authorCls = $tw
        ? 'notes-library__author tn-th-meta-muted tn-m-0 tn-mt-1 tn-leading-snug'
        : 'notes-library__author';
    $groupsCls = 'notes-library__groups';
    $permalinkP = $tw ? 'notes-library__permalink tn-th-item-footer tn-m-0' : 'notes-library__permalink';
    $permalinkA = $tw ? ' tn-text-xs tn-font-medium tn-text-slate-400 tn-no-underline hover:tn-text-tn-accent hover:tn-underline' : '';
    ?>
                        <li class="<?= e($cardLi) ?>">
                            <?php if ($tw): ?>
                                <header class="<?= e($postHeadCls) ?>">
                                    <div class="tn-th-meta-row">
                                        <time
                                            class="notes-library__date tn-th-meta-accent tn-shrink-0"
                                            datetime="<?= e((string) $noteRow['entry_date']) ?>"
                                        ><?= e($dateLabel) ?></time>
                                        <?php if ($groupsLabel !== ''): ?>
                                            <span class="tn-text-slate-300 tn-select-none" aria-hidden="true">·</span>
                                            <span class="<?= e($groupsCls) ?> tn-th-meta-muted tn-min-w-0">Shared in <?= e($groupsLabel) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$isMine): ?>
                                        <p class="<?= e($authorCls) ?>"><?= e($authorLabel) ?></p>
                                    <?php endif; ?>
                                </header>
                            <?php endif; ?>
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
                                <?php if (!$tw): ?>
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
                                <?php endif; ?>
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
