<?php
/**
 * footer.php — Closes main content and renders the reusable bottom navigation.
 *
 * Expects:
 *   $currentNav (string) — 'today' | 'notes' | 'groups' | 'me'
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/assets.php';

$currentNav = $currentNav ?? 'today';
$showNav = $showNav ?? true;

$pendingGroupInviteCount = 0;
if ($showNav) {
    $footerNavUserId = current_user_id();
    if ($footerNavUserId !== null) {
        require_once __DIR__ . '/includes/group_helpers.php';
        $footerGroupPdo = db();
        $pendingGroupInviteCount = count(group_invitations_pending_for_user($footerGroupPdo, $footerNavUserId))
            + group_invite_requests_pending_count_for_owner($footerGroupPdo, $footerNavUserId);
    }
}
?>
        </main>

        <?php if ($showNav): ?>
            <nav class="bottom-nav" aria-label="Primary">
                <a href="/index.php" class="bottom-nav__link <?= $currentNav === 'today' ? 'is-active' : '' ?>">
                    <span class="bottom-nav__label">Today</span>
                </a>
                <a href="/notes.php" class="bottom-nav__link <?= $currentNav === 'notes' ? 'is-active' : '' ?>">
                    <span class="bottom-nav__label">Notes</span>
                </a>
                <a
                    href="/groups.php"
                    class="bottom-nav__link <?= $currentNav === 'groups' ? 'is-active' : '' ?>"
                    <?= $pendingGroupInviteCount > 0 ? 'aria-describedby="nav-groups-pending-desc"' : '' ?>
                >
                    <span class="bottom-nav__labelWrap">
                        <?php if ($pendingGroupInviteCount > 0): ?>
                            <span id="nav-groups-pending-desc" class="visually-hidden"><?= (int) $pendingGroupInviteCount ?> pending <?= $pendingGroupInviteCount === 1 ? 'item' : 'items' ?> on Groups—invitations for you or invite requests to approve</span>
                        <?php endif; ?>
                        <span class="bottom-nav__label">Groups</span>
                        <?php if ($pendingGroupInviteCount > 0): ?>
                            <span class="bottom-nav__badge" aria-hidden="true"><?= $pendingGroupInviteCount > 9 ? '9+' : (string) (int) $pendingGroupInviteCount ?></span>
                        <?php endif; ?>
                    </span>
                </a>
                <a href="/me.php" class="bottom-nav__link <?= $currentNav === 'me' ? 'is-active' : '' ?>">
                    <span class="bottom-nav__label">Me</span>
                </a>
            </nav>
        <?php endif; ?>

        <dialog id="site-photo-lightbox" class="photo-lightbox" aria-label="Enlarged photo">
            <div class="photo-lightbox__panel">
                <button type="button" class="photo-lightbox__close" aria-label="Close">×</button>
                <img class="photo-lightbox__img" src="" alt="">
            </div>
        </dialog>
        <script src="<?= e(asset_url('/image_lightbox.js')) ?>"></script>
        <?php if (current_user_id() !== null): ?>
            <?php require_once __DIR__ . '/includes/csrf.php'; ?>
            <script>
                (function () {
                    var csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                    var html = document.documentElement;
                    var saved = html.getAttribute('data-user-timezone') || '';
                    var tz = '';
                    try {
                        tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                    } catch (e) {}
                    if (!tz || tz === saved) {
                        return;
                    }
                    fetch('/timezone_save.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf,
                        },
                        body: JSON.stringify({ timezone: tz }),
                        credentials: 'same-origin',
                    })
                        .then(function (r) {
                            if (r.ok) {
                                html.setAttribute('data-user-timezone', tz);
                            }
                        })
                        .catch(function () {});
                })();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
