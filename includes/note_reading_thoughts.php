<?php
/**
 * Canonical inline reading surface for note thoughts (reactions + full text + comments).
 * Used by note detail, Notes list / Shared-with-you cards, and Today — Yours (readonly).
 */
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/escape.php';
require_once __DIR__ . '/note_thoughts.php';
require_once __DIR__ . '/thought_comments.php';
require_once __DIR__ . '/user_timezone.php';

/**
 * One thought: thought-block markup matching note detail behavior.
 *
 * @param array{id:int,body:string,created_at:string,is_private:bool,entry_date?:string} $th
 * @param list<array{emoji:string,count:int,reacted_by_me?:bool}> $thoughtReactions
 * @param list<array{id:int,user_id:int,body:string,created_at:string,display_name:string}> $thoughtCommentsList
 * @param array{can_edit?:bool}|null $todayExtras When set (Today — Yours), may render edit/delete controls in meta row.
 */
function note_reading_render_thought_block(
    int $tid,
    array $th,
    array $thoughtReactions,
    bool $showThoughtComments,
    array $thoughtCommentsList,
    bool $canPostThoughtComment,
    int $viewerUserId,
    string $redirectTarget,
    bool $thoughtCommentsIconOnlyComposer,
    bool $isMine,
    string $viewerTz,
    ?array $todayExtras = null,
    bool $notesTailwindUi = false,
): void {
    $userId = $viewerUserId;
    $canEditTodayThought = !empty($todayExtras['can_edit']);
    $tw = $notesTailwindUi;
    $blockCls = $tw ? 'thought-block tn-space-y-3' : 'thought-block';
    $bodyCls = $tw
        ? 'thought-block__body note-detail__thought-body tn-m-0 tn-max-w-none tn-text-base md:tn-text-[1.0625rem] tn-leading-[1.65] tn-text-slate-800 tn-font-normal'
        : 'thought-block__body note-detail__thought-body';
    $metaCls = $tw
        ? 'thought-block__meta tn-flex tn-flex-wrap tn-items-center tn-gap-x-2 tn-gap-y-1 tn-pt-3 tn-mt-0.5 tn-w-full'
        : 'thought-block__meta';
    $rxCls = $tw ? 'thought-reactions tn-gap-2 tn-opacity-95' : 'thought-reactions';
    $timeCls = $tw
        ? 'thought-block__time note-detail__thought-time tn-text-xs tn-text-slate-500 tn-shrink-0 tn-font-normal'
        : 'thought-block__time note-detail__thought-time';
    ?>
                                                <div class="<?= e($blockCls) ?>">
                                                    <div class="thought-block__text">
                                                        <p class="<?= e($bodyCls) ?>"><?php
                                                            if ($isMine && !empty($th['is_private'])) {
                                                                echo '<span class="note-detail__thought-private-wrap" role="img" aria-label="Private — only visible to you"><span class="note-detail__thought-private" aria-hidden="true">🔒</span></span>';
                                                            }
                                                            echo nl2br(e(trim((string) $th['body'])));
                                                            ?></p>
                                                    </div>
                                                    <div class="<?= e($metaCls) ?>">
                                                        <span
                                                            class="<?= e($rxCls) ?>"
                                                            data-thought-reactions
                                                            data-thought-id="<?= $tid ?>"
                                                        >
                                                            <span class="thought-reactions__list" data-reaction-list>
                                                                <?php foreach ($thoughtReactions as $rx): ?>
                                                                    <button
                                                                        type="button"
                                                                        class="thought-reaction-pill<?= ($rx['reacted_by_me'] ?? false) ? ' is-active' : '' ?>"
                                                                        data-reaction-toggle="1"
                                                                        data-thought-id="<?= $tid ?>"
                                                                        data-emoji="<?= e($rx['emoji']) ?>"
                                                                        aria-label="Toggle reaction <?= e($rx['emoji']) ?>"
                                                                    ><?= e($rx['emoji']) ?> <?= (int) $rx['count'] ?></button>
                                                                <?php endforeach; ?>
                                                            </span>
                                                            <button
                                                                type="button"
                                                                class="thought-reaction-add"
                                                                data-reaction-add="1"
                                                                data-thought-id="<?= $tid ?>"
                                                                aria-label="Add reaction"
                                                            >+</button>
                                                        </span>
                                                        <time class="<?= e($timeCls) ?>" datetime="<?= e(datetime_attr_utc_mysql($th['created_at'])) ?>"><?= e(note_thought_time_label($th['created_at'], $viewerTz)) ?></time>
                                                        <?php if ($canEditTodayThought): ?>
                                                            <span class="thought-block__actions today-yours-thought-actions" aria-label="Thought actions">
                                                                <button type="button" class="today-thought__icon-btn" data-thought-edit-open="<?= $tid ?>" title="Edit" aria-label="Edit moment">✏️</button>
                                                                <form class="today-thought-delete-form" method="post" action="/index.php">
                                                                    <?php csrf_hidden_field(); ?>
                                                                    <input type="hidden" name="today_action" value="delete_thought">
                                                                    <input type="hidden" name="thought_id" value="<?= $tid ?>">
                                                                    <button type="submit" class="today-thought__icon-btn today-thought__icon-btn--danger" title="Delete" aria-label="Delete moment" onclick="return confirm('Remove this moment?');">🗑</button>
                                                                </form>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($showThoughtComments): ?>
                                                        <?php
                                                        $thoughtId = $tid;
                                                        $comments = $thoughtCommentsList;
                                                        $canPostComment = $canPostThoughtComment;
                                                        require __DIR__ . '/thought_comments_section.php';
                                                        ?>
                                                    <?php endif; ?>
                                                </div>
    <?php
}

/**
 * Full list of thoughts for a note (same structure as note detail).
 *
 * @param list<array{id:int,note_id:int,body:string,created_at:string,is_private:bool,entry_date?:string}> $thoughts
 * @param array<int, list<array{emoji:string,count:int,reacted_by_me?:bool}>> $thoughtReactionMap
 * @param array<int, list<array{id:int,user_id:int,body:string,created_at:string,display_name:string}>> $thoughtCommentsMap
 */
function note_reading_render_thoughts_list(
    int $viewerUserId,
    array $thoughts,
    array $thoughtReactionMap,
    array $thoughtCommentsMap,
    bool $noteSharedWithGroup,
    bool $isMine,
    string $commentRedirectTarget,
    string $viewerTz,
    bool $thoughtCommentsIconOnlyComposer = false,
    bool $notesTailwindUi = false,
): void {
    $tw = $notesTailwindUi;
    $listCls = $tw
        ? 'note-detail__thoughts tn-m-0 tn-list-none tn-space-y-7 md:tn-space-y-8 tn-p-0 tn-max-w-none'
        : 'note-detail__thoughts';
    $itemCls = $tw
        ? 'note-detail__thought tn-m-0 tn-pl-0 tn-border-0 tn-pt-2 first:tn-pt-0'
        : 'note-detail__thought';
    ?>
                <ul class="<?= e($listCls) ?>">
                    <?php foreach ($thoughts as $th): ?>
                        <?php
                        $tid = (int) $th['id'];
                        $thoughtReactions = $thoughtReactionMap[$tid] ?? [];
                        $showThoughtComments = !$th['is_private'] && $noteSharedWithGroup;
                        $thoughtCommentsList = $thoughtCommentsMap[$tid] ?? [];
                        $noteEntry = isset($th['entry_date']) && is_string($th['entry_date']) ? $th['entry_date'] : null;
                        $canPostThoughtComment = $showThoughtComments && thought_comment_post_allowed($th['created_at'], $viewerTz, $noteEntry);
                        ?>
                        <li class="<?= e($itemCls) ?>">
                            <?php
                            note_reading_render_thought_block(
                                $tid,
                                $th,
                                $thoughtReactions,
                                $showThoughtComments,
                                $thoughtCommentsList,
                                $canPostThoughtComment,
                                $viewerUserId,
                                $commentRedirectTarget,
                                $thoughtCommentsIconOnlyComposer,
                                $isMine,
                                $viewerTz,
                                null,
                                $notesTailwindUi,
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
    <?php
}
