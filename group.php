<?php
/**
 * group.php — Group detail: members; owner sees pending invites + invite form.
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

$gStmt = $pdo->prepare('SELECT id, name FROM `groups` WHERE id = ? LIMIT 1');
$gStmt->execute([$groupId]);
$groupRow = $gStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($groupRow)) {
    header('Location: /groups.php');
    exit;
}

$mStmt = $pdo->prepare(
    <<<'SQL'
    SELECT u.display_name, gm.joined_at
    FROM group_members gm
    INNER JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ?
    ORDER BY gm.joined_at ASC, u.id ASC
    SQL
);
$mStmt->execute([$groupId]);
$members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingInvites = [];
if ($isOwner) {
    $pStmt = $pdo->prepare(
        <<<'SQL'
        SELECT email, created_at, expires_at
        FROM group_invitations
        WHERE group_id = ?
          AND accepted_at IS NULL
          AND expires_at > NOW()
        ORDER BY created_at DESC
        SQL
    );
    $pStmt->execute([$groupId]);
    $pendingInvites = $pStmt->fetchAll(PDO::FETCH_ASSOC);
}

$created = isset($_GET['created']);
$inviteSent = isset($_GET['invite_sent']);
$joined = isset($_GET['joined']);

$pageTitle = (string) $groupRow['name'];
$currentNav = 'groups';

require_once __DIR__ . '/header.php';
?>

            <p class="sub-nav">
                <a href="/groups.php">← Groups</a>
            </p>

            <?php if ($created): ?>
                <p class="flash" role="status">Group created.</p>
            <?php endif; ?>

            <?php if ($inviteSent): ?>
                <p class="flash" role="status">If that address can receive mail, an invitation is on the way.</p>
            <?php endif; ?>

            <?php if ($joined): ?>
                <p class="flash" role="status">You’ve joined this group.</p>
            <?php endif; ?>

            <section class="detail-section">
                <h2 class="detail-section__title">Members</h2>
                <?php if (count($members) === 0): ?>
                    <p class="empty-state">No members yet.</p>
                <?php else: ?>
                    <ul class="member-list">
                        <?php foreach ($members as $m): ?>
                            <li class="member-list__item">
                                <span class="member-list__name"><?= e((string) $m['display_name']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($isOwner): ?>
                <section class="detail-section">
                    <h2 class="detail-section__title">Pending invitations</h2>
                    <?php if (count($pendingInvites) === 0): ?>
                        <p class="empty-state">No pending invitations.</p>
                    <?php else: ?>
                        <ul class="invite-list">
                            <?php foreach ($pendingInvites as $inv): ?>
                                <li class="invite-list__item"><?= e((string) $inv['email']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

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
                </section>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
