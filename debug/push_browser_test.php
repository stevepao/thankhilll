<?php
/**
 * debug/push_browser_test.php — Run push subscribe/unsubscribe endpoint checks in the browser (logged-in only).
 *
 * Uses your current session (no CLI). After subscribe/unsubscribe checks, exercises PushService
 * (minishlink/web-push) queue path only — leave PUSH_SENDING_ENABLED unset/false so fake test
 * endpoints are never POSTed.
 * Removes only the test subscription rows it creates. Safe for production if you trust logged-in users.
 *
 * Delete this file when you no longer need it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/push_subscription_repository.php';
require_once dirname(__DIR__) . '/includes/PushService.php';

$userId = require_login();

/** @var list<array{label: string, pass: bool, detail: string}>|null */
$testReport = null;

/**
 * @return array{0: int, 1: string}
 */
function push_browser_test_post_json(string $url, string $jsonBody, string $cookieHeader, string $userAgent): array
{
    if (!function_exists('curl_init')) {
        return [0, 'curl extension required'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [0, ''];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Cookie: ' . $cookieHeader,
            'User-Agent: ' . $userAgent,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, is_string($body) ? $body : ''];
}

function push_browser_test_absolute_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

$formAction = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/debug/push_browser_test.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$runPushBrowserTest = (string) ($_POST['run_push_browser_test'] ?? '') === '1'
    || (string) ($_POST['run_push_browser_test_btn'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runPushBrowserTest) {
    csrf_verify_post_or_abort();

    try {
        $pdo = db();
        $report = [];
        $cookie = $_SERVER['HTTP_COOKIE'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'PushBrowserTest/1.0';
        $base = push_browser_test_absolute_base();
        $csrf = csrf_token();

        if ($cookie === '') {
            $testReport = [
                [
                    'label' => 'Prerequisite',
                    'pass' => false,
                    'detail' => 'No HTTP Cookie header — enable cookies for this site and try again.',
                ],
            ];
        } else {
        $countSubs = static function () use ($pdo, $userId): int {
            return count(push_subscription_list_for_user($pdo, $userId));
        };

        $before = $countSubs();

        $endpoint = 'https://fcm.googleapis.com/fcm/send/browser-test-' . bin2hex(random_bytes(16));
        $p256dh = 'Bp_test_' . rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '=');
        $auth = 'auth_t_' . rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $p256dh2 = 'Bp_test2_' . rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '=');
        $auth2 = 'auth_t2_' . rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        $post = static function (string $path, array $body) use ($base, $cookie, $ua): array {
            $url = $base . $path;
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);

            return push_browser_test_post_json(
                $url,
                is_string($json) ? $json : '{}',
                $cookie,
                $ua
            );
        };

        // Step 1
        [$c1, $b1] = $post('/push/subscribe.php', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
            'csrf_token' => $csrf,
        ]);
        $j1 = json_decode($b1, true);
        $id1 = is_array($j1) && isset($j1['id']) ? (int) $j1['id'] : 0;
        $after1 = $countSubs();
        $report[] = [
            'label' => 'POST /push/subscribe (first)',
            'pass' => $c1 === 200 && ($j1['ok'] ?? false) === true && $after1 === $before + 1,
            'detail' => 'HTTP ' . $c1 . ' body: ' . substr($b1, 0, 180) . (strlen($b1) > 180 ? '…' : ''),
        ];

        // Step 2 — same endpoint, new keys (no duplicate row)
        [$c2, $b2] = $post('/push/subscribe.php', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $p256dh2, 'auth' => $auth2],
            'csrf_token' => $csrf,
        ]);
        $j2 = json_decode($b2, true);
        $id2 = is_array($j2) && isset($j2['id']) ? (int) $j2['id'] : 0;
        $after2 = $countSubs();
        $report[] = [
            'label' => 'POST /push/subscribe (same endpoint — upsert)',
            'pass' => $c2 === 200 && ($j2['ok'] ?? false) === true && $id2 === $id1 && $id1 > 0 && $after2 === $before + 1,
            'detail' => 'HTTP ' . $c2 . ' id=' . $id2 . ' (same as first id) count=' . $after2 . ' (expect ' . ($before + 1) . ')',
        ];

        // Step 3 — unsubscribe
        [$c3, $b3] = $post('/push/unsubscribe.php', [
            'endpoint' => $endpoint,
            'csrf_token' => $csrf,
        ]);
        $after3 = $countSubs();
        $report[] = [
            'label' => 'POST /push/unsubscribe',
            'pass' => $c3 === 200 && str_contains($b3, '"ok":true') && $after3 === $before,
            'detail' => 'HTTP ' . $c3 . ' count after: ' . $after3 . ' (expect baseline ' . $before . ')',
        ];

        // Step 4 — idempotent
        [$c4, $b4] = $post('/push/unsubscribe.php', [
            'endpoint' => $endpoint,
            'csrf_token' => $csrf,
        ]);
        $after4 = $countSubs();
        $report[] = [
            'label' => 'POST /push/unsubscribe again',
            'pass' => $c4 === 200 && str_contains($b4, '"ok":true') && $after4 === $before,
            'detail' => 'HTTP ' . $c4,
        ];

        // Step 5 — re-subscribe then cleanup row only for this test endpoint
        [$c5, $b5] = $post('/push/subscribe.php', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
            'csrf_token' => $csrf,
        ]);
        $j5 = json_decode($b5, true);
        $after5 = $countSubs();
        $report[] = [
            'label' => 'POST /push/subscribe (re-create)',
            'pass' => $c5 === 200 && ($j5['ok'] ?? false) === true && $after5 === $before + 1,
            'detail' => 'HTTP ' . $c5,
        ];

        $libRows = push_subscription_list_for_user($pdo, $userId);
        $libResult = push_service_queue_and_flush($libRows);
        $libJson = json_encode($libResult, JSON_UNESCAPED_SLASHES);
        $libDetail = is_string($libJson)
            ? (strlen($libJson) > 240 ? substr($libJson, 0, 240) . '…' : $libJson)
            : '(encode error)';
        $report[] = [
            'label' => 'PushService queue (minishlink); no browser delivery asserted',
            'pass' => push_service_diagnostic_result_acceptable($libResult, 1),
            'detail' => $libDetail,
        ];

        push_subscription_delete_by_endpoint_for_user($pdo, $userId, $endpoint);
        $afterCleanup = $countSubs();
        $report[] = [
            'label' => 'Cleanup (delete test endpoint only)',
            'pass' => $afterCleanup === $before,
            'detail' => 'Subscriptions for your account back to baseline count ' . $before . '.',
        ];

            $testReport = $report;
        }
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
                This page calls <code>/push/subscribe.php</code> and <code>/push/unsubscribe.php</code> using your current login,
                then runs <code>PushService</code> (library queue only if VAPID is configured). Keep
                <code>PUSH_SENDING_ENABLED</code> unset or false so test endpoints are not contacted over HTTP.
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
