<?php
/**
 * groups.php — List groups the current user belongs to.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/group_helpers.php';

$userId = require_login();
$pdo = db();
$groups = groups_for_user_with_counts($pdo, $userId);

$pageTitle = 'Groups';
$currentNav = 'groups';

require_once __DIR__ . '/header.php';
?>

            <p class="stack-actions">
                <a class="btn btn--primary" href="/group_new.php">Create a group</a>
            </p>

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
