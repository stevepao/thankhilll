<?php
/**
 * me.php — Signed-in profile landing (Me tab).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();
$user = currentUser();

$pageTitle = 'Me';
$currentNav = 'me';

require_once __DIR__ . '/header.php';
?>

            <?php if ($user !== null): ?>
                <p class="profile-block">
                    <span class="profile-block__label">Signed in as</span>
                    <span class="profile-block__name"><?= e((string) ($user['display_name'] ?? '')) ?></span>
                </p>
            <?php endif; ?>

            <p>
                <a class="btn btn--primary" href="/auth/logout.php">Log out</a>
            </p>

<?php require_once __DIR__ . '/footer.php'; ?>
