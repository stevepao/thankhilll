<?php
/**
 * groups.php — List groups the current user belongs to.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/group_helpers.php';

$userId = require_login();
$pdo = db();
$pendingInvites = group_invitations_pending_for_user($pdo, $userId);
$groups = groups_for_user_with_counts($pdo, $userId);

$pageTitle = 'Groups';
$currentNav = 'groups';
$inviteDeclinedFlash = isset($_GET['invite_declined']);
$inviteErrFlash = isset($_GET['invite_err']);

require_once __DIR__ . '/header.php';
?>

            <p class="stack-actions">
                <a class="btn btn--primary" href="/group_new.php">Create a group</a>
            </p>

            <?php if ($inviteDeclinedFlash): ?>
                <p class="flash" role="status">Invitation declined.</p>
            <?php endif; ?>
            <?php if ($inviteErrFlash): ?>
                <p class="flash flash--error" role="alert">That invitation is no longer available.</p>
            <?php endif; ?>

            <?php if (count($pendingInvites) > 0): ?>
                <section class="detail-section group-invites-pending" aria-labelledby="pending-invites-heading">
                    <h2 id="pending-invites-heading" class="detail-section__title">Pending invitations</h2>
                    <ul class="group-invites-pending__list">
                        <?php foreach ($pendingInvites as $inv): ?>
                            <?php
                            $expTs = strtotime((string) $inv['expires_at']);
                            $expLabel = $expTs ? date('M j, Y', $expTs) : '';
                            ?>
                            <li class="group-invites-pending__card">
                                <div class="group-invites-pending__body">
                                    <span class="group-invites-pending__group"><?= e($inv['group_name']) ?></span>
                                    <span class="group-invites-pending__meta">
                                        Invited by <?= e($inv['invited_by_display_name']) ?>
                                        <?php if ($expLabel !== ''): ?>
                                            · Expires <?= e($expLabel) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="group-invites-pending__actions">
                                    <form method="post" action="/group_invitation_respond.php" class="group-invites-pending__form">
                                        <?php csrf_hidden_field(); ?>
                                        <input type="hidden" name="invitation_id" value="<?= (int) $inv['id'] ?>">
                                        <input type="hidden" name="decision" value="accept">
                                        <button type="submit" class="btn btn--primary">Accept</button>
                                    </form>
                                    <form method="post" action="/group_invitation_respond.php" class="group-invites-pending__form">
                                        <?php csrf_hidden_field(); ?>
                                        <input type="hidden" name="invitation_id" value="<?= (int) $inv['id'] ?>">
                                        <input type="hidden" name="decision" value="decline">
                                        <button type="submit" class="btn btn--ghost">Decline</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if (count($groups) === 0): ?>
                <p class="empty-state">You are not in any groups yet. Create one to share gratitude privately with people you trust.</p>
            <?php else: ?>
                <ul class="group-list">
                    <?php foreach ($groups as $g): ?>
                        <li class="group-card">
                            <a href="/group.php?id=<?= (int) $g['id'] ?>" class="group-card__link">
                                <span class="group-card__name"><?= e($g['name']) ?></span>
                                <span class="group-card__meta"><?= (int) $g['member_count'] ?> members</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
