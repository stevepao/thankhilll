<?php
/**
 * group.php — Group detail: role-aware layout; admin invitations/members/lifecycle; member request/members/leave.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/escape.php';
require_once __DIR__ . '/includes/group_helpers.php';

$userId = require_login();

$groupId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($groupId <= 0) {
    header('Location: /groups.php');
    exit;
}

$pdo = db();

if (!user_is_group_member($pdo, $userId, $groupId)) {
    http_response_code(404);
    $pageTitle = 'Not found';
    $currentNav = 'groups';
    require_once __DIR__ . '/header.php';
    echo '<p class="empty-state">This group is not available.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$isOwner = user_is_group_owner($pdo, $userId, $groupId);

$gStmt = $pdo->prepare('SELECT id, name, owner_user_id FROM `groups` WHERE id = ? LIMIT 1');
$gStmt->execute([$groupId]);
$groupRow = $gStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($groupRow)) {
    header('Location: /groups.php');
    exit;
}

$ownerUserId = (int) $groupRow['owner_user_id'];

$mStmt = $pdo->prepare(
    <<<'SQL'
    SELECT gm.user_id, u.display_name, gm.joined_at
    FROM group_members gm
    INNER JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ?
    ORDER BY gm.joined_at ASC, u.id ASC
    SQL
);
$mStmt->execute([$groupId]);
$members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

usort($members, static function (array $a, array $b) use ($ownerUserId): int {
    $aid = (int) $a['user_id'];
    $bid = (int) $b['user_id'];
    $aAdmin = $aid === $ownerUserId ? 0 : 1;
    $bAdmin = $bid === $ownerUserId ? 0 : 1;
    if ($aAdmin !== $bAdmin) {
        return $aAdmin <=> $bAdmin;
    }

    return strcmp((string) $a['joined_at'], (string) $b['joined_at']);
});

$pendingInvites = [];
$pendingInviteRequests = [];
if ($isOwner) {
    $pStmt = $pdo->prepare(
        <<<'SQL'
        SELECT email, created_at, expires_at
        FROM group_invitations
        WHERE group_id = ?
          AND accepted_at IS NULL
          AND declined_at IS NULL
          AND expires_at > NOW()
        ORDER BY created_at DESC
        SQL
    );
    $pStmt->execute([$groupId]);
    $pendingInvites = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingInviteRequests = group_invite_requests_pending_for_group($pdo, $groupId);
}

$created = isset($_GET['created']);
$inviteSent = isset($_GET['invite_sent']);
$joined = isset($_GET['joined']);
$inviteRequestSent = isset($_GET['invite_request_sent']);
$inviteRequestDeclined = isset($_GET['invite_request_declined']);
$inviteRequestApproved = isset($_GET['invite_request_approved']);
$inviteRequestErr = isset($_GET['invite_request_err']) ? (string) $_GET['invite_request_err'] : '';
$highlightInviteRequests = isset($_GET['invite_requests']);
$leaveErrOwner = isset($_GET['leave_err']) && $_GET['leave_err'] === 'owner';

$deleteGroupConfirmMsg = "Delete this group?\n\n"
    . "The group will be removed for everyone. Shared access through this group will stop.\n\n"
    . "None of your notes or thoughts will be deleted—they stay on each person’s account.\n\n"
    . "This cannot be undone.";

if ($isOwner) {
    $groupRoleLabel = 'You’re the group admin';
    $groupRoleHelper = 'You send invitations, review requests from members, and manage who’s in this group.';
} else {
    $groupRoleLabel = 'You’re a member';
    $groupRoleHelper = 'You can ask the admin to invite someone new, or leave this group whenever you like.';
}

$pageTitle = (string) $groupRow['name'];
$currentNav = 'groups';

require_once __DIR__ . '/header.php';
?>

            <p class="sub-nav">
                <a href="/groups.php">← Groups</a>
            </p>

            <header class="group-detail-header detail-section">
                <h1 class="group-detail-header__title"><?= e((string) $groupRow['name']) ?></h1>
                <p class="group-detail-header__role"><?= e($groupRoleLabel) ?></p>
                <p class="muted-note group-detail-header__helper"><?= e($groupRoleHelper) ?></p>
            </header>

            <?php if ($created): ?>
                <p class="flash" role="status">Group created.</p>
            <?php endif; ?>

            <?php if ($inviteSent): ?>
                <p class="flash" role="status">If that address can receive mail, an invitation is on the way.</p>
            <?php endif; ?>

            <?php if ($joined): ?>
                <p class="flash" role="status">You’ve joined this group.</p>
            <?php endif; ?>

            <?php if ($inviteRequestSent): ?>
                <p class="flash" role="status">Your request has been sent to the group admin.</p>
            <?php endif; ?>

            <?php if ($inviteRequestErr !== ''): ?>
                <?php if ($inviteRequestErr === 'invalid'): ?>
                    <p class="flash flash--error" role="alert">Enter a valid email address.</p>
                <?php elseif ($inviteRequestErr === 'self'): ?>
                    <p class="flash flash--error" role="alert">You can’t request an invite for your own address.</p>
                <?php elseif ($inviteRequestErr === 'already_member'): ?>
                    <p class="flash flash--error" role="alert">That person is already in this group.</p>
                <?php elseif ($inviteRequestErr === 'duplicate'): ?>
                    <p class="flash flash--error" role="alert">There is already a pending request for that address.</p>
                <?php elseif ($inviteRequestErr === 'pending_invite'): ?>
                    <p class="flash flash--error" role="alert">That address already has a pending invitation.</p>
                <?php elseif ($inviteRequestErr === 'approve_failed' && $isOwner): ?>
                    <p class="flash flash--error" role="alert">Could not send the invitation. Try again or invite by email directly.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($isOwner && $inviteRequestDeclined): ?>
                <p class="flash" role="status">Invite request dismissed.</p>
            <?php endif; ?>

            <?php if ($isOwner && $inviteRequestApproved !== ''): ?>
                <?php if ($inviteRequestApproved === '1'): ?>
                    <p class="flash" role="status">Invitation sent for that request.</p>
                <?php elseif ($inviteRequestApproved === 'already_member'): ?>
                    <p class="flash" role="status">That person is already a member — request cleared.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($leaveErrOwner && $isOwner): ?>
                <p class="flash flash--error" role="alert">You can’t leave while you’re the group admin.</p>
            <?php endif; ?>

            <?php if ($isOwner): ?>
                <section
                    class="detail-section<?= $highlightInviteRequests ? ' detail-section--highlight' : '' ?>"
                    aria-labelledby="invitations-requests-heading"
                >
                    <h2 id="invitations-requests-heading" class="detail-section__title">Invitations & requests</h2>

                    <form class="note-form stack-top" method="post" action="/group_invite_send.php">
                        <?php csrf_hidden_field(); ?>
                        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                        <label class="note-form__label" for="invite_email">Invite by email</label>
                        <input
                            id="invite_email"
                            name="email"
                            type="email"
                            class="note-form__input"
                            maxlength="255"
                            autocomplete="email"
                            placeholder="friend@example.com"
                        >
                        <button type="submit" class="btn btn--primary">Send invitation</button>
                    </form>

                    <div id="pending-invite-requests" class="group-invitations-stack">
                        <h3 class="detail-section__subtitle">Pending invite requests</h3>
                        <?php if (count($pendingInviteRequests) === 0): ?>
                            <p class="empty-state">No pending requests from members.</p>
                        <?php else: ?>
                            <ul class="invite-request-list">
                                <?php foreach ($pendingInviteRequests as $pr): ?>
                                    <li class="invite-request-list__item">
                                        <div class="invite-request-list__body">
                                            <p class="invite-request-list__email"><?= e($pr['requested_email']) ?></p>
                                            <p class="invite-request-list__meta">
                                                Requested by <?= e($pr['requester_display_name']) ?>
                                                · <?= e($pr['created_at']) ?>
                                            </p>
                                        </div>
                                        <div class="invite-request-list__actions">
                                            <form method="post" action="/group_invite_request_respond.php" class="inline-form-row">
                                                <?php csrf_hidden_field(); ?>
                                                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $pr['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn--primary btn--small">Approve</button>
                                            </form>
                                            <form method="post" action="/group_invite_request_respond.php" class="inline-form-row">
                                                <?php csrf_hidden_field(); ?>
                                                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $pr['id'] ?>">
                                                <input type="hidden" name="action" value="decline">
                                                <button type="submit" class="btn btn--ghost btn--small">Ignore</button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="group-invitations-stack">
                        <h3 class="detail-section__subtitle">Pending invitations</h3>
                        <?php if (count($pendingInvites) === 0): ?>
                            <p class="empty-state">No pending invitations.</p>
                        <?php else: ?>
                            <ul class="invite-list">
                                <?php foreach ($pendingInvites as $inv): ?>
                                    <li class="invite-list__item"><?= e((string) $inv['email']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="detail-section" aria-labelledby="request-invite-heading">
                    <h2 id="request-invite-heading" class="detail-section__title">Request invite</h2>
                    <p class="muted-note">Ask the group admin to send an invitation. You won’t see the admin’s email address.</p>
                    <form class="note-form stack-top" method="post" action="/group_invite_request_submit.php">
                        <?php csrf_hidden_field(); ?>
                        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                        <label class="note-form__label" for="request_invite_email">Their email address</label>
                        <input
                            id="request_invite_email"
                            name="email"
                            type="email"
                            class="note-form__input"
                            maxlength="255"
                            autocomplete="email"
                            placeholder="friend@example.com"
                            required
                        >
                        <button type="submit" class="btn btn--primary">Request invite</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="detail-section" aria-labelledby="members-heading">
                <h2 id="members-heading" class="detail-section__title">Members</h2>
                <?php if (count($members) === 0): ?>
                    <p class="empty-state">No members yet.</p>
                <?php else: ?>
                    <ul class="member-list">
                        <?php foreach ($members as $m): ?>
                            <?php
                            $mid = (int) $m['user_id'];
                            $isGroupAdmin = $mid === $ownerUserId;
                            ?>
                            <li class="member-list__item<?= $isGroupAdmin ? ' member-list__item--admin' : '' ?>">
                                <div class="member-list__primary">
                                    <span class="member-list__name"><?= e((string) $m['display_name']) ?></span>
                                    <?php if ($isGroupAdmin): ?>
                                        <span class="member-list__badge">Group admin</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isGroupAdmin): ?>
                                    <p class="member-list__meta">Responsible for approving invites and adding members.</p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if (!$isOwner): ?>
                <section class="detail-section leave-group-section" aria-labelledby="leave-group-heading">
                    <h2 id="leave-group-heading" class="detail-section__title">Membership</h2>
                    <p class="muted-note">
                        Membership is voluntary. If you leave, you lose access to this group until someone invites you again.
                    </p>
                    <form
                        class="leave-group-form stack-top"
                        method="post"
                        action="/group_leave.php"
                        onsubmit="return confirm('Leave this group?');"
                    >
                        <?php csrf_hidden_field(); ?>
                        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                        <div class="leave-group-form__actions">
                            <button type="submit" class="btn btn--danger-secondary">Leave group</button>
                            <a href="/groups.php" class="btn btn--ghost leave-group-form__cancel">Cancel</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($isOwner): ?>
                <section class="detail-section delete-group-section group-lifecycle-section" aria-labelledby="lifecycle-heading">
                    <h2 id="lifecycle-heading" class="detail-section__title">Group lifecycle</h2>
                    <p class="muted-note">
                        You manage invites and membership for this group. Leaving isn’t available while you’re the only admin—ownership transfer isn’t supported yet.
                    </p>
                    <div class="group-lifecycle-delete">
                        <h3 class="detail-section__subtitle">Delete group</h3>
                        <p class="muted-note">
                            Remove this group for every member. Sharing through this group ends; notes and thoughts are not deleted.
                        </p>
                        <form
                            class="delete-group-form stack-top"
                            method="post"
                            action="/group_delete.php"
                            onsubmit='return confirm(<?= json_encode($deleteGroupConfirmMsg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);'
                        >
                            <?php csrf_hidden_field(); ?>
                            <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                            <button type="submit" class="btn btn--danger-fill">Delete group</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
