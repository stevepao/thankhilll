<?php
/**
 * me.php — Profile, preferences, and account (personal only).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/app_url.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/user_export.php';
require_once __DIR__ . '/includes/user_timezone.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/user_notification_prefs_repository.php';

$userId = require_login();
$pdo = db();

$flashProfile = isset($_GET['saved']) && $_GET['saved'] === 'profile';
$flashPrefs = isset($_GET['saved']) && $_GET['saved'] === 'prefs';
$flashExportRequested = isset($_GET['export_requested']) && $_GET['export_requested'] === '1';
$flashExportBusy = isset($_GET['export_busy']) && $_GET['export_busy'] === '1';
$flashExportErr = isset($_GET['export_err']) && $_GET['export_err'] === '1';
$flashExportDeleted = isset($_GET['export_deleted']) && $_GET['export_deleted'] === '1';
$flashExportDeleteErr = isset($_GET['export_delete_err']) && $_GET['export_delete_err'] === '1';
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
    } elseif ($save === 'data_export') {
        if (!user_export_table_exists($pdo)) {
            header('Location: ' . app_absolute_url('/me.php?export_err=1'));
            exit;
        }
        $res = user_export_enqueue($pdo, $userId);
        if (!$res['ok']) {
            $msg = (string) ($res['error'] ?? '');
            if ($msg !== '' && str_contains($msg, 'already have')) {
                header('Location: ' . app_absolute_url('/me.php?export_busy=1'));
            } else {
                header('Location: ' . app_absolute_url('/me.php?export_err=1'));
            }
            exit;
        }
        header('Location: ' . app_absolute_url('/me.php?export_requested=1'));
        exit;
    } elseif ($save === 'delete_export') {
        if (!user_export_table_exists($pdo)) {
            header('Location: ' . app_absolute_url('/me.php?export_err=1'));
            exit;
        }
        $delId = isset($_POST['export_id']) ? (int) $_POST['export_id'] : 0;
        $delRes = user_export_user_delete_ready($pdo, $userId, $delId);
        if (!$delRes['ok']) {
            header('Location: ' . app_absolute_url('/me.php?export_delete_err=1'));
            exit;
        }
        header('Location: ' . app_absolute_url('/me.php?export_deleted=1'));
        exit;
    }
}

$exportsTableOk = user_export_table_exists($pdo);
$exportRows = [];
if ($exportsTableOk) {
    $exportRows = user_export_list_for_user($pdo, $userId, 12);
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

$createdDt = user_datetime_immutable_utc((string) $userRow['created_at']);
$accountSince = '';
if ($createdDt !== null) {
    $viewerZ = new DateTimeZone(user_timezone_get($pdo, $userId));
    $accountSince = $createdDt->setTimezone($viewerZ)->format('M j, Y');
}

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

            <?php if ($flashExportRequested): ?>
                <p class="flash" role="status">Export requested. Processing runs on a schedule, so you should get an email within about a day when it’s ready—you can also open Me anytime to check.</p>
            <?php endif; ?>
            <?php if ($flashExportBusy): ?>
                <p class="flash flash--error" role="alert">You already have an export in progress. Please wait until it finishes.</p>
            <?php endif; ?>
            <?php if ($flashExportErr): ?>
                <p class="flash flash--error" role="alert">That export request couldn’t be recorded. Try again, or ask your host whether data export is enabled.</p>
            <?php endif; ?>
            <?php if ($flashExportDeleted): ?>
                <p class="flash" role="status">That export file was removed from the server.</p>
            <?php endif; ?>
            <?php if ($flashExportDeleteErr): ?>
                <p class="flash flash--error" role="alert">That export couldn’t be removed. It may already be gone—refresh Me.</p>
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
                <p class="me-muted me-notifications__autosave">Changes here are saved automatically.</p>
                <div
                    id="me-notifications-root"
                    class="me-notifications"
                    data-csrf="<?= e(csrf_token()) ?>"
                    data-gratitude-reminder-account="<?= $notifPrefs['push_reminders_enabled'] ? '1' : '0' ?>"
                >
                    <p class="me-muted">
                        Push is optional. We only ask for browser permission when you turn this on.
                    </p>
                    <div class="me-notifications__item">
                        <label class="me-check" for="me-push-gratitude-reminder">
                            <input
                                type="checkbox"
                                id="me-push-gratitude-reminder"
                                <?= $notifPrefs['push_reminders_enabled'] ? 'checked' : '' ?>
                            >
                            <span>Remind me to write a gratitude note</span>
                        </label>
                        <p class="me-muted me-notifications__hint">
                            You’ll get one gentle reminder in the evening if you haven’t written yet.
                        </p>
                    </div>
                    <div class="me-notifications__item">
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
                    </div>
                    <p class="me-push-status me-notifications__status" id="me-push-status" role="status" aria-live="polite"></p>

                    <dialog id="me-gratitude-prepermission-dialog" class="me-dialog" aria-labelledby="me-gratitude-prepermission-title">
                        <h3 id="me-gratitude-prepermission-title" class="me-dialog__title">Enable notifications on this device?</h3>
                        <p class="me-dialog__body">
                            We can remind you to write something you're grateful for.
                            This uses your browser’s notification system.
                        </p>
                        <div class="me-dialog__actions">
                            <button type="button" class="btn btn--primary" id="me-gratitude-prepermission-enable">Enable notifications</button>
                            <button type="button" class="btn btn--ghost" id="me-gratitude-prepermission-dismiss">Not now</button>
                        </div>
                    </dialog>

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

                    <dialog id="me-cross-device-push-dialog" class="me-dialog" aria-labelledby="me-cross-device-title">
                        <h3 id="me-cross-device-title" class="me-dialog__title">Notifications are enabled on your account.</h3>
                        <p class="me-dialog__body">Enable them on this device too?</p>
                        <div class="me-dialog__actions">
                            <button type="button" class="btn btn--primary" id="me-cross-device-enable">Enable notifications</button>
                            <button type="button" class="btn btn--ghost" id="me-cross-device-dismiss">Not now</button>
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
                        <option value="last_used_groups" <?= ($prefs['default_note_visibility'] ?? '') === 'last_used_groups' ? 'selected' : '' ?>>
                            Remember the groups I last shared with
                        </option>
                        <option value="private" <?= ($prefs['default_note_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>
                            Start private (choose sharing each time)
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

            <section class="me-section" aria-labelledby="me-your-data-heading">
                <h2 id="me-your-data-heading" class="me-section__heading">Your data</h2>
                <p class="me-muted">
                    Request a ZIP of your notes, photos you uploaded, and activity we associate with your account.
                    Exports are prepared when your site’s scheduled job runs (on many hosts that’s only a few times a day). Expect an email within about a day; you can always check here while signed in, too.
                </p>
                <?php if (!$exportsTableOk): ?>
                    <p class="me-muted">This feature isn’t available until your database is updated (run migrations).</p>
                <?php else: ?>
                    <form class="me-form" method="post" action="/me.php">
                        <?php csrf_hidden_field(); ?>
                        <input type="hidden" name="save" value="data_export">
                        <button type="submit" class="btn btn--ghost me-form__submit">Request data export</button>
                    </form>
                    <?php if (count($exportRows) > 0): ?>
                        <h3 class="me-section__subheading">Recent exports</h3>
                        <ul class="me-export-list">
                            <?php foreach ($exportRows as $er): ?>
                                <?php
                                if (!is_array($er)) {
                                    continue;
                                }
                                $eid = (int) ($er['id'] ?? 0);
                                $st = (string) ($er['status'] ?? '');
                                $reqLabel = user_mysql_utc_label(isset($er['requested_at']) ? (string) $er['requested_at'] : null);
                                $delLabel = user_mysql_utc_label(isset($er['deleted_at']) ? (string) $er['deleted_at'] : null);
                                $errMsg = isset($er['error_message']) && is_string($er['error_message']) ? trim($er['error_message']) : '';
                                $shortErr = $errMsg !== '' ? (mb_strlen($errMsg) > 160 ? mb_substr($errMsg, 0, 157) . '…' : $errMsg) : '';
                                $statusLabel = match ($st) {
                                    'queued' => 'Queued — waiting to start',
                                    'running' => 'Running — preparing files',
                                    'ready' => 'Ready — download below',
                                    'failed' => 'Failed',
                                    'deleted_by_user' => 'Deleted by user',
                                    default => $st,
                                };
                                ?>
                                <li class="me-export-list__item">
                                    <div class="me-export-list__meta">
                                        <span class="me-export-list__status"><?= e($statusLabel) ?></span>
                                        <?php if ($st === 'deleted_by_user' && $delLabel !== ''): ?>
                                            <span class="me-export-list__time">Deleted <?= e($delLabel) ?></span>
                                        <?php else: ?>
                                            <span class="me-export-list__time">Requested <?= e($reqLabel) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($st === 'ready'): ?>
                                        <div class="me-export-list__actions">
                                            <a class="btn btn--primary me-export-list__download" href="<?= e(app_absolute_url('/me_export_download.php?export_id=' . $eid)) ?>">Download ZIP</a>
                                            <form
                                                class="me-export-list__delete-form"
                                                method="post"
                                                action="/me.php"
                                                onsubmit="return confirm('Remove this export from the server? You can request a new export later.');"
                                            >
                                                <?php csrf_hidden_field(); ?>
                                                <input type="hidden" name="save" value="delete_export">
                                                <input type="hidden" name="export_id" value="<?= $eid ?>">
                                                <button type="submit" class="btn btn--ghost me-export-list__delete">Delete export</button>
                                            </form>
                                        </div>
                                    <?php elseif ($st === 'failed' && $shortErr !== ''): ?>
                                        <p class="me-muted me-export-list__err"><?= e($shortErr) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="me-section" aria-labelledby="me-account-heading">
                <h2 id="me-account-heading" class="me-section__heading">Account &amp; data</h2>
                <?php if ($accountSince !== ''): ?>
                    <p class="me-muted">Member since <?= e($accountSince) ?>.</p>
                <?php endif; ?>
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
