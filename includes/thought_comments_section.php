<?php
/**
 * Renders comment list + optional composer for one thought.
 *
 * Expects: $thoughtId (int), $comments (list), $canPostComment (bool), $userId (int), $redirectTarget (string)
 * Optional: $thoughtCommentsIconOnlyComposer — Today uses true (💬 only); note detail uses false (shows “Add comment” when list empty).
 */
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/escape.php';
require_once __DIR__ . '/thought_comments.php';

/** @var int $thoughtId */
/** @var list<array{id:int,user_id:int,body:string,created_at:string,display_name:string}> $comments */
/** @var bool $canPostComment */
/** @var int $userId */
/** @var string $redirectTarget */

/** When true (e.g. Today card), composer summary shows icon only; otherwise show 💬 + subtle cue when list empty. */
$thoughtCommentsIconOnlyComposer = $thoughtCommentsIconOnlyComposer ?? false;

/** @var string $viewerTz */
$viewerTz = isset($viewerTz) && is_string($viewerTz) ? $viewerTz : 'UTC';

$showClosedHint = !$canPostComment && $comments === [];
?>
                    <div class="thought-comments" id="thought-comments-<?= (int) $thoughtId ?>">
                        <?php if ($comments !== []): ?>
                            <ul class="thought-comments__list">
                                <?php foreach ($comments as $c): ?>
                                    <?php
                                    $cid = (int) $c['id'];
                                    $canDeleteThis = ((int) $c['user_id']) === $userId && thought_comment_delete_window_open($c['created_at'], $viewerTz);
                                    ?>
                                    <li class="thought-comments__item" id="comment-<?= $cid ?>">
                                        <p class="thought-comments__body"><?= nl2br(e($c['body'])) ?></p>
                                        <div class="thought-comments__footer">
                                            <p class="thought-comments__meta">
                                                <span class="thought-comments__author"><?= e($c['display_name']) ?></span>
                                                <span class="thought-comments__sep"> · </span>
                                                <time class="thought-comments__time" datetime="<?= e(datetime_attr_utc_mysql($c['created_at'])) ?>"><?= e(thought_comment_time_label($c['created_at'], $viewerTz)) ?></time>
                                            </p>
                                            <?php if ($canDeleteThis): ?>
                                                <form class="thought-comments__delete-form" method="post" action="/comments/delete.php">
                                                    <?php csrf_hidden_field(); ?>
                                                    <input type="hidden" name="comment_id" value="<?= $cid ?>">
                                                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">
                                                    <button type="submit" class="thought-comments__icon-btn thought-comments__icon-btn--remove" title="Remove comment" aria-label="Remove comment">×</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($canPostComment): ?>
                            <?php
                            $composerSummaryAria =
                                ($thoughtCommentsIconOnlyComposer || $comments !== [])
                                    ? ' aria-label="Add comment"'
                                    : '';
                            ?>
                            <details class="thought-comments__composer-wrap">
                                <summary class="thought-comments__composer-summary"<?= $composerSummaryAria ?>>
                                    <span class="thought-comments__composer-icon" aria-hidden="true">💬</span>
                                    <?php if (!$thoughtCommentsIconOnlyComposer && $comments === []): ?>
                                        <span class="thought-comments__composer-label">Add comment</span>
                                    <?php endif; ?>
                                </summary>
                                <form class="thought-comments__form note-form note-form--compact" method="post" action="/comments/create.php">
                                    <?php csrf_hidden_field(); ?>
                                    <input type="hidden" name="thought_id" value="<?= (int) $thoughtId ?>">
                                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">
                                    <label class="visually-hidden" for="comment-body-<?= (int) $thoughtId ?>">Comment</label>
                                    <textarea
                                        id="comment-body-<?= (int) $thoughtId ?>"
                                        name="body"
                                        class="thought-comments__textarea note-form__textarea"
                                        rows="2"
                                        maxlength="<?= (int) THOUGHT_COMMENT_MAX_LENGTH ?>"
                                        placeholder="A brief note of warmth…"
                                    ></textarea>
                                    <p class="thought-comments__hint share-fieldset__hint"><?= (int) THOUGHT_COMMENT_MAX_LENGTH ?> characters max.</p>
                                    <button type="submit" class="btn btn--ghost thought-comments__submit">Post</button>
                                </form>
                            </details>
                        <?php elseif ($showClosedHint): ?>
                            <p class="thought-comments__closed-hint share-fieldset__hint">
                                Comments can be added on the same calendar day as this moment, or within 24 hours after it was posted.
                            </p>
                        <?php endif; ?>
                    </div>
