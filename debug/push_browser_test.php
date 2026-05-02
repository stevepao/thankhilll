<?php
/**
 * debug/push_browser_test.php — Run push subscribe/unsubscribe endpoint checks in the browser (logged-in only).
 *
 * Uses your current session (no CLI). Subscription steps call push_subscription_* directly (same
 * logic as /push/subscribe and /push/unsubscribe). HTTP loopback curl would deadlock when PHP
 * serves only one request at a time (e.g. php -S or a single worker). For full HTTP+session tests
 * use debug/push_endpoints_cli_test.php. WebPush wiring step follows minishlink/web-push README
 * (WebPush, Subscription::create, queueNotification, flush → MessageSentReport); configure VAPID in .env.
 * Removes only the test subscription rows it creates. Safe for production if you trust logged-in users.
 *
 * Delete this file when you no longer need it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/push_subscription_repository.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

$userId = require_login();

/** @var list<array{label: string, pass: bool, detail: string}>|null */
$testReport = null;

$formAction = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/debug/push_browser_test.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$runPushBrowserTest = (string) ($_POST['run_push_browser_test'] ?? '') === '1'
    || (string) ($_POST['run_push_browser_test_btn'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runPushBrowserTest) {
    csrf_verify_post_or_abort();

    try {
        $pdo = db();
        $report = [];
        $countSubs = static function () use ($pdo, $userId): int {
            return count(push_subscription_list_for_user($pdo, $userId));
        };

        $before = $countSubs();

        $endpoint = 'https://fcm.googleapis.com/fcm/send/browser-test-' . bin2hex(random_bytes(16));
        // Must be plain base64url (no ASCII prefixes): library Base64Url-decodes these for encryption/VAPID.
        $b64url = static fn (int $bytes): string => rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        $p256dh = $b64url(65);
        $auth = $b64url(16);
        $p256dh2 = $b64url(65);
        $auth2 = $b64url(16);

        // Step 1 — same as POST /push/subscribe (repository), avoids curl loopback deadlock.
        $id1 = push_subscription_upsert($pdo, $userId, $endpoint, $p256dh, $auth, null, null);
        $after1 = $countSubs();
        $report[] = [
            'label' => 'Subscribe — first (in-process)',
            'pass' => $id1 > 0 && $after1 === $before + 1,
            'detail' => 'id=' . $id1 . ' count=' . $after1,
        ];

        // Step 2 — same endpoint, new keys (no duplicate row)
        $id2 = push_subscription_upsert($pdo, $userId, $endpoint, $p256dh2, $auth2, null, null);
        $after2 = $countSubs();
        $report[] = [
            'label' => 'Subscribe — same endpoint, new keys (upsert)',
            'pass' => $id2 === $id1 && $id1 > 0 && $after2 === $before + 1,
            'detail' => 'id=' . $id2 . ' count=' . $after2 . ' (expect ' . ($before + 1) . ')',
        ];

        // Step 3 — unsubscribe
        push_subscription_delete_by_endpoint_for_user($pdo, $userId, $endpoint);
        $after3 = $countSubs();
        $report[] = [
            'label' => 'Unsubscribe — delete by endpoint',
            'pass' => $after3 === $before,
            'detail' => 'count=' . $after3 . ' (expect baseline ' . $before . ')',
        ];

        // Step 4 — idempotent
        push_subscription_delete_by_endpoint_for_user($pdo, $userId, $endpoint);
        $after4 = $countSubs();
        $report[] = [
            'label' => 'Unsubscribe again (idempotent)',
            'pass' => $after4 === $before,
            'detail' => 'count=' . $after4,
        ];

        // Step 5 — re-subscribe then cleanup row only for this test endpoint
        $id5 = push_subscription_upsert($pdo, $userId, $endpoint, $p256dh, $auth, null, null);
        $after5 = $countSubs();
        $report[] = [
            'label' => 'Subscribe — re-create',
            'pass' => $id5 > 0 && $after5 === $before + 1,
            'detail' => 'id=' . $id5 . ' count=' . $after5,
        ];

        // minishlink/web-push README: Send Push Message + VAPID (same flow as library docs).
        loadEnv();
        $smokeRows = push_subscription_list_for_user($pdo, $userId);
        $readmePass = false;
        $readmeDetail = '';
        $firstSmoke = $smokeRows[0] ?? null;
        if ($firstSmoke === null) {
            $readmeDetail = 'No subscription row for wiring step.';
        } else {
            try {
                $subscription = Subscription::create([
                    'endpoint' => (string) ($firstSmoke['endpoint_url'] ?? ''),
                    'keys' => [
                        'p256dh' => (string) ($firstSmoke['p256dh'] ?? ''),
                        'auth' => (string) ($firstSmoke['auth'] ?? ''),
                    ],
                ]);

                $auth = [
                    'VAPID' => [
                        'subject' => env_var('VAPID_SUBJECT'),
                        'publicKey' => env_var('VAPID_PUBLIC_KEY'),
                        'privateKey' => env_var('VAPID_PRIVATE_KEY'),
                    ],
                ];

                $webPush = new WebPush($auth);
                // README allows null payload (optional). Wiring-only: skips payload encryption so fake DB keys need not be a valid EC point.
                $webPush->queueNotification($subscription, null);

                $readmeReports = [];
                foreach ($webPush->flush() as $sentReport) {
                    $readmeReports[] = [
                        'success' => $sentReport->isSuccess(),
                        'reason' => $sentReport->getReason(),
                    ];
                }

                $readmePass = $readmeReports !== [];
                $enc = json_encode($readmeReports, JSON_UNESCAPED_SLASHES);
                $readmeDetail = is_string($enc)
                    ? (strlen($enc) > 280 ? substr($enc, 0, 280) . '…' : $enc)
                    : '';
            } catch (Throwable $e) {
                $readmeDetail = $e->getMessage();
            }
        }

        $report[] = [
            'label' => 'Library README wiring: WebPush → queueNotification → flush (MessageSentReport)',
            'pass' => $readmePass,
            'detail' => $readmeDetail !== '' ? $readmeDetail : '(empty)',
        ];

        push_subscription_delete_by_endpoint_for_user($pdo, $userId, $endpoint);
        $afterCleanup = $countSubs();
        $report[] = [
            'label' => 'Cleanup (delete test endpoint only)',
            'pass' => $afterCleanup === $before,
            'detail' => 'Subscriptions for your account back to baseline count ' . $before . '.',
        ];

        $testReport = $report;
    } catch (Throwable $e) {
        error_log('push_browser_test: ' . $e->getMessage());
        $testReport = [
            [
                'label' => 'Server error',
                'pass' => false,
                'detail' => $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')',
            ],
        ];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST reached this URL but our fields were missing (proxy strips body, wrong action, etc.)
    $testReport = [
        [
            'label' => 'Form POST not recognized',
            'pass' => false,
            'detail' => 'This page received POST without run_push_browser_test=1. Try again from this URL, or check that the form posts to ' . ($formAction) . '.',
        ],
    ];
}

$pageTitle = 'Push endpoints test';
$currentNav = '';
$showNav = true;

require_once dirname(__DIR__) . '/header.php';
?>

            <p class="email-auth__hint">
                Subscription steps run the same database logic as <code>/push/subscribe</code> and
                <code>/push/unsubscribe</code> in-process (calling those URLs via curl here would deadlock
                single-worker PHP). For HTTP endpoint coverage use <code>debug/push_endpoints_cli_test.php</code>.
                The last step runs the <strong>minishlink/web-push README</strong> pattern (<code>WebPush</code>,
                <code>Subscription::create</code>, <code>queueNotification(null)</code>, <code>flush</code>) — null payload is valid in the README and skips payload encryption so synthetic DB keys do not need to be a real EC key.
                Keys are read with <code>env_var()</code> (<code>$_ENV</code> plus <code>getenv()</code>), since some PHP installs leave <code>$_ENV</code> empty.
                <code>flush()</code> still performs VAPID signing and HTTP; fake endpoints usually return failed <code>MessageSentReport</code> rows, which is fine for wiring.
            </p>

            <?php if ($testReport !== null): ?>
                <section class="note-card" style="margin-top: 1rem;">
                    <h2 class="email-auth__headline" style="font-size: 1rem;">Results</h2>
                    <ul style="margin: 0.5rem 0 0; padding-left: 1.2rem;">
                        <?php foreach ($testReport as $row): ?>
                            <li style="margin-bottom: 0.5rem;">
                                <strong><?= $row['pass'] ? 'PASS' : 'FAIL' ?></strong> —
                                <?= e($row['label']) ?>
                                <br>
                                <span style="color: var(--muted); font-size: 0.9rem;"><?= e($row['detail']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <form method="post" action="<?= $formAction ?>" id="push-browser-test-form" style="margin-top: 1.25rem;">
                <?php csrf_hidden_field(); ?>
                <input type="hidden" name="run_push_browser_test" value="1">
                <button type="submit" name="run_push_browser_test_btn" value="1" class="btn btn--primary">Run tests</button>
            </form>

            <p class="empty-state" style="margin-top: 1.5rem;"><a href="/me.php">Back to Me</a></p>

<?php require_once dirname(__DIR__) . '/footer.php'; ?>
