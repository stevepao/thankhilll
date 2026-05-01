<?php
/**
 * includes/note_library_card.php — Linked preview card for note library lists (Notes + Today shared).
 */
declare(strict_types=1);

require_once __DIR__ . '/note_preview.php';
require_once __DIR__ . '/escape.php';

const NOTE_LIBRARY_CARD_PREVIEW_MAX = 220;

/**
 * @param list<array{body:string,id?:int}> $thoughtRows
 */
function note_library_card_preview_blob(array $thoughtRows): string
{
    $blob = '';
    foreach ($thoughtRows as $tr) {
        $blob .= ($blob !== '' ? "\n\n" : '') . trim((string) $tr['body']);
    }

    return $blob;
}

/**
 * @param list<array{id:int}> $thoughtRows
 * @param array<int, list<array{emoji:string,count:int}>> $reactionByThoughtMap
 * @return array<string, int> emoji => total count for note
 */
function note_library_card_aggregate_reactions(array $thoughtRows, array $reactionByThoughtMap): array
{
    $noteReactions = [];
    foreach ($thoughtRows as $tr) {
        $tid = (int) $tr['id'];
        foreach ($reactionByThoughtMap[$tid] ?? [] as $rx) {
            $emoji = (string) $rx['emoji'];
            if (!isset($noteReactions[$emoji])) {
                $noteReactions[$emoji] = 0;
            }
            $noteReactions[$emoji] += (int) $rx['count'];
        }
    }

    return $noteReactions;
}

/**
 * @param array{id:int,entry_date:string,user_id:int,author_name?:string,shared_group_names?:string|null} $noteRow
 * @param list<array{id:int,body:string}> $thoughtRows
 * @param list<array{id:int,width:int,height:int}> $thumbs
 * @param array<int, list<array{emoji:string,count:int}>> $reactionByThoughtMap
 */
function note_library_card_render(
    array $noteRow,
    int $viewerUserId,
    array $thoughtRows,
    array $thumbs,
    array $reactionByThoughtMap,
    int $previewMaxChars = NOTE_LIBRARY_CARD_PREVIEW_MAX
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

    $preview = note_plain_preview(note_library_card_preview_blob($thoughtRows), $previewMaxChars);
    $noteReactions = note_library_card_aggregate_reactions($thoughtRows, $reactionByThoughtMap);
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
                            <a class="notes-library__card-main" href="/note.php?id=<?= $nid ?>">
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
                                <?php if (count($noteReactions) > 0): ?>
                                    <p class="notes-library__reactions" aria-label="Thought reactions">
                                        <?php foreach ($noteReactions as $emoji => $count): ?>
                                            <span class="thought-reaction-pill"><?= e($emoji) ?> <?= (int) $count ?></span>
                                        <?php endforeach; ?>
                                    </p>
                                <?php endif; ?>
                                <p class="notes-library__preview"><?= e($preview) ?></p>
                            </a>
                        </li>
    <?php
}
