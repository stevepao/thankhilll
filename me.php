<?php
/**
 * me.php — Profile, preferences, and account (personal only).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/user_notification_prefs_repository.php';

$userId = require_login();
$pdo = db();

$flashProfile = isset($_GET['saved']) && $_GET['saved'] === 'profile';
$flashPrefs = isset($_GET['saved']) && $_GET['saved'] === 'prefs';
$notifPrefs = user_notification_prefs_get($pdo, $userId);
$deleteErr = isset($_GET['delete_err']) ? (string) $_GET['delete_err'] : '';
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $save = $_POST['save'] ?? '';
    if ($save === 'profile') {
        $nameRaw = $_POST['display_name'] ?? '';
        $nameTrim = trim(is_string($nameRaw) ? $nameRaw : '');
        if ($nameTrim === '') {
            $errorMessage = 'Please enter a display name.';
        } elseif (function_exists('mb_strlen') && mb_strlen($nameTrim, 'UTF-8') > 120) {
            $errorMessage = 'That name is too long.';
        } elseif (!function_exists('mb_strlen') && strlen($nameTrim) > 120) {
            $errorMessage = 'That name is too long.';
        } else {
            $upd = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
            $upd->execute([$nameTrim, $userId]);
            header('Location: /me.php?saved=profile');
            exit;
        }
    } elseif ($save === 'prefs') {
        $vis = $_POST['default_note_visibility'] ?? '';
        $defaultNoteVisibility = ($vis === 'last_used_groups') ? 'last_used_groups' : 'private';

        $notesScope = $_POST['notes_default_scope'] ?? '';
        $notesDefaultScope = ($notesScope === 'mine') ? 'mine' : 'all';

        $todayShared = isset($_POST['today_show_shared'])
            && (string) $_POST['today_show_shared'] === '1';

        user_preferences_merge_save($pdo, $userId, [
            'default_note_visibility' => $defaultNoteVisibility,
            'today_show_shared' => $todayShared,
            'notes_default_scope' => $notesDefaultScope,
        ]);

        header('Location: /me.php?saved=prefs');
        exit;
    }
}

$stmt = $pdo->prepare(
    'SELECT id, display_name, preferences_json, created_at FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($userRow)) {
    http_response_code(500);
    echo 'Profile unavailable.';
    exit;
}

$prefsRaw = $userRow['preferences_json'] ?? null;
$prefsDecoded = null;
if (is_string($prefsRaw) && $prefsRaw !== '') {
    $prefsDecoded = json_decode($prefsRaw, true);
}
$prefs = user_preferences_normalize(is_array($prefsDecoded) ? $prefsDecoded : []);

$provStmt = $pdo->prepare(
    'SELECT DISTINCT provider FROM auth_identities WHERE user_id = ? ORDER BY provider ASC'
);
$provStmt->execute([$userId]);
$providers = $provStmt->fetchAll(PDO::FETCH_COLUMN);

$emailStmt = $pdo->prepare(
    'SELECT identifier FROM auth_identities WHERE user_id = ? AND provider = \'email\'
     ORDER BY last_used_at DESC, id DESC LIMIT 1'
);
$emailStmt->execute([$userId]);
$primaryEmail = $emailStmt->fetchColumn();
$primaryEmail = is_string($primaryEmail) ? $primaryEmail : '';

$providerLines = [];
foreach ($providers as $p) {
    if (!is_string($p)) {
        continue;
    }
    if ($p === 'google') {
        $providerLines[] = 'Signed in with Google';
    } elseif ($p === 'email') {
        $providerLines[] = 'Email';
    } else {
        $providerLines[] = $p;
    }
}

$createdTs = strtotime((string) $userRow['created_at']);
$accountSince = $createdTs ? date('M j, Y', $createdTs) : '';

$pageTitle = 'Me';
$currentNav = 'me';

require_once __DIR__ . '/header.php';
?>

            <?php if ($flashProfile): ?>
                <p class="flash" role="status">Your name was updated.</p>
            <?php endif; ?>

            <?php if ($flashPrefs): ?>
                <p class="flash" role="status">Preferences saved.</p>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <p class="flash flash--error" role="alert"><?= e($errorMessage) ?></p>
            <?php endif; ?>

            <?php if ($deleteErr === 'confirm' || $deleteErr === 'understand'): ?>
                <p class="flash flash--error" role="alert">Confirm every step to delete your account.</p>
            <?php elseif ($deleteErr === 'failed'): ?>
                <p class="flash flash--error" role="alert">Your account could not be deleted. Try again or contact support.</p>
            <?php endif; ?>

            <section class="me-section" aria-labelledby="me-profile-heading">
                <h2 id="me-profile-heading" class="me-section__heading">Profile</h2>
                <dl class="me-dl">
                    <div class="me-dl__row">
                        <dt class="me-dl__dt">Name</dt>
                        <dd class="me-dl__dd"><?= e((string) $userRow['display_name']) ?></dd>
                    </div>
                    <div class="me-dl__row">
                        <dt class="me-dl__dt">Email</dt>
                        <dd class="me-dl__dd"><?= $primaryEmail !== '' ? e($primaryEmail) : '—' ?></dd>
                    </div>
                    <div class="me-dl__row">
                        <dt class="me-dl__dt">Sign-in</dt>
                        <dd class="me-dl__dd">
                            <?php if (count($providerLines) === 0): ?>
                                —
                            <?php else: ?>
                                <?= e(implode(' · ', $providerLines)) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>

                <form class="me-form" method="post" action="/me.php">
                    <?php csrf_hidden_field(); ?>
                    <input type="hidden" name="save" value="profile">
                    <label class="note-form__label" for="display_name">Display name</label>
                    <input
                        id="display_name"
                        name="display_name"
                        type="text"
                        class="note-form__input"
                        maxlength="120"
                        autocomplete="nickname"
                        value="<?= e((string) $userRow['display_name']) ?>"
                    >
                    <button type="submit" class="btn btn--primary me-form__submit">Save name</button>
                </form>
            </section>

            <section class="me-section" aria-labelledby="me-notifications-heading">
                <h2 id="me-notifications-heading" class="me-section__heading">Notifications</h2>
                <div
                    id="me-notifications-root"
                    class="me-notifications"
                    data-csrf="<?= e(csrf_token()) ?>"
                >
                    <p class="me-muted">
                        Push is optional. We only ask for browser permission when you turn this on.
                    </p>
                    <label class="me-check" for="me-push-comment-replies">
                        <input
                            type="checkbox"
                            id="me-push-comment-replies"
                            <?= $notifPrefs['push_comment_replies_enabled'] ? 'checked' : '' ?>
                        >
                        <span>Notify me when someone comments on my notes or thoughts</span>
                    </label>
                    <p class="me-muted me-notifications__hint">
                        You’ll get a push notification when someone leaves a comment on something you wrote.
                    </p>
                    <p class="me-push-status" id="me-push-status" role="status" aria-live="polite"></p>

                    <dialog id="me-push-prepermission-dialog" class="me-dialog" aria-labelledby="me-push-prepermission-title">
                        <h3 id="me-push-prepermission-title" class="me-dialog__title">Enable notifications on this device?</h3>
                        <p class="me-dialog__body">
                            We can notify you when someone comments on something you wrote. This uses your browser’s notification system.
                        </p>
                        <div class="me-dialog__actions">
                            <button type="button" class="btn btn--primary" id="me-push-prepermission-enable">Enable notifications</button>
                            <button type="button" class="btn btn--ghost" id="me-push-prepermission-dismiss">Not now</button>
                        </div>
                    </dialog>
                </div>
                <script src="<?= e(asset_url('/me_notifications.js')) ?>" defer></script>
            </section>

            <section class="me-section" aria-labelledby="me-prefs-heading">
                <h2 id="me-prefs-heading" class="me-section__heading">Preferences</h2>
                <form class="me-form" method="post" action="/me.php">
                    <?php csrf_hidden_field(); ?>
                    <input type="hidden" name="save" value="prefs">

                    <h3 class="me-section__subheading">Writing</h3>

                    <label class="note-form__label" for="default_note_visibility">New notes on Today</label>
                    <select class="notes-filters__select me-form__select" id="default_note_visibility" name="default_note_visibility">
                        <option value="private" <?= ($prefs['default_note_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>
                            Start private (choose sharing each time)
                        </option>
                        <option value="last_used_groups" <?= ($prefs['default_note_visibility'] ?? '') === 'last_used_groups' ? 'selected' : '' ?>>
                            Remember the groups I last shared with
                        </option>
                    </select>

                    <h3 class="me-section__subheading">Viewing</h3>

                    <input type="hidden" name="today_show_shared" value="0">
                    <label class="me-check">
                        <input
                            type="checkbox"
                            name="today_show_shared"
                            value="1"
                            <?= !empty($prefs['today_show_shared']) ? 'checked' : '' ?>
                        >
                        <span>Show shared gratitude on Today</span>
                    </label>

                    <label class="note-form__label" for="notes_default_scope">Notes opens with</label>
                    <select class="notes-filters__select me-form__select" id="notes_default_scope" name="notes_default_scope">
                        <option value="all" <?= ($prefs['notes_default_scope'] ?? '') === 'all' ? 'selected' : '' ?>>
                            All notes you can see
                        </option>
                        <option value="mine" <?= ($prefs['notes_default_scope'] ?? '') === 'mine' ? 'selected' : '' ?>>
                            Just your own notes
                        </option>
                    </select>

                    <button type="submit" class="btn btn--primary me-form__submit">Save preferences</button>
                </form>
            </section>

            <section class="me-section" aria-labelledby="me-account-heading">
                <h2 id="me-account-heading" class="me-section__heading">Account &amp; data</h2>
                <?php if ($accountSince !== ''): ?>
                    <p class="me-muted">Member since <?= e($accountSince) ?>.</p>
                <?php endif; ?>
                <p class="me-muted">Export your data: <em>coming soon</em>.</p>
                <p>
                    <a class="btn btn--primary" href="/auth/logout.php">Sign out</a>
                </p>
            </section>

            <section class="me-section delete-account-section" aria-labelledby="me-delete-heading">
                <h2 id="me-delete-heading" class="me-section__heading">Delete account</h2>
                <p class="me-muted">
                    Permanently removes your profile, sign-in methods, notes, thoughts, photos, reactions, and comments you wrote.
                    Groups you joined stay for other members. Groups you administered stay in the list without an assigned admin.
                    Other people’s content is not deleted. If you used Google Sign-In, this app revokes its access to your Google account when possible; you can also review connected apps in your Google Account settings.
                </p>
                <form
                    class="me-form"
                    method="post"
                    action="/account_delete.php"
                    onsubmit="return confirm('Permanently delete your account and everything listed above? This cannot be undone.');"
                >
                    <?php csrf_hidden_field(); ?>
                    <label class="me-check">
                        <input type="checkbox" name="understand" value="1" required>
                        <span>I understand this permanently deletes my account and my data.</span>
                    </label>
                    <label class="note-form__label" for="delete_confirmation">Type DELETE to confirm</label>
                    <input
                        id="delete_confirmation"
                        name="confirmation"
                        type="text"
                        class="note-form__input"
                        autocomplete="off"
                        autocapitalize="characters"
                        placeholder="DELETE"
                    >
                    <button type="submit" class="btn btn--danger-fill me-form__submit">Delete my account permanently</button>
                </form>
            </section>

<?php require_once __DIR__ . '/footer.php'; ?>
