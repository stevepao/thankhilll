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
$ownerInviteRequestCounts = group_invite_requests_pending_counts_for_owner_groups($pdo, $userId);

$pageTitle = 'Groups';
$currentNav = 'groups';
$inviteDeclinedFlash = isset($_GET['invite_declined']);
$inviteErrFlash = isset($_GET['invite_err']);
$leftGroupFlash = isset($_GET['left_group']);
$groupDeletedFlash = isset($_GET['group_deleted']);
$showYourGroupsHeading = count($pendingInvites) > 0 && count($groups) > 0;

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
            <?php if ($leftGroupFlash): ?>
                <p class="flash" role="status">You’ve left the group.</p>
            <?php endif; ?>
            <?php if ($groupDeletedFlash): ?>
                <p class="flash" role="status">The group has been deleted.</p>
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
                <?php if ($showYourGroupsHeading): ?>
                    <section class="detail-section" aria-labelledby="your-groups-heading">
                        <h2 id="your-groups-heading" class="detail-section__title">Your groups</h2>
                <?php endif; ?>
                        <ul class="group-list">
                            <?php foreach ($groups as $g): ?>
                                <?php
                                $gid = (int) $g['id'];
                                $inviteReqPending = (int) ($ownerInviteRequestCounts[$gid] ?? 0);
                                ?>
                                <li class="group-card">
                                    <a href="/group.php?id=<?= $gid ?><?= $inviteReqPending > 0 ? '&invite_requests=1#pending-invite-requests' : '' ?>" class="group-card__link">
                                        <span class="group-card__title-row">
                                            <span class="group-card__name"><?= e($g['name']) ?></span>
                                            <?php if ($inviteReqPending > 0): ?>
                                                <?php
                                                $badgeLabel = $inviteReqPending === 1
                                                    ? '1 invite request pending'
                                                    : $inviteReqPending . ' invite requests pending';
                                                ?>
                                                <span class="group-card__badge" title="<?= e($badgeLabel) ?>" aria-label="<?= e($badgeLabel) ?>"><?= $inviteReqPending > 9 ? '9+' : (string) $inviteReqPending ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="group-card__meta"><?= (int) $g['member_count'] ?> members</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                <?php if ($showYourGroupsHeading): ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
