<?php
/**
 * includes/PushService.php — Central Web Push sending via minishlink/web-push (no manual crypto/jwt/http).
 *
 * Requires: composer deps, ext-openssl, ext-curl, ext-mbstring, ext-json.
 * Subscription storage stays in push_subscription_repository.php.
 */
declare(strict_types=1);

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Build a Subscription from a push_subscriptions DB row (opaque keys; no decoding).
 */
function push_service_subscription_from_row(array $row): Subscription
{
    return Subscription::create([
        'endpoint' => (string) ($row['endpoint_url'] ?? ''),
        'keys' => [
            'p256dh' => (string) ($row['p256dh'] ?? ''),
            'auth' => (string) ($row['auth'] ?? ''),
        ],
    ]);
}

/**
 * True when PUSH_SENDING_ENABLED is explicitly truthy (1, true, yes, on).
 * Default false when unset — no network sends until you opt in.
 */
function push_service_sending_enabled(): bool
{
    if (!function_exists('loadEnv')) {
        require_once dirname(__DIR__) . '/db.php';
    }
    loadEnv();

    $raw = $_ENV['PUSH_SENDING_ENABLED'] ?? getenv('PUSH_SENDING_ENABLED');
    if ($raw === false || $raw === null || $raw === '') {
        return false;
    }

    $s = strtolower(trim((string) $raw));

    return \in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Dry-run / diagnostics: validate keys and queue crypto without opening network connections.
 */
function push_service_diagnostic_dry_run_only(): bool
{
    if (!function_exists('loadEnv')) {
        require_once dirname(__DIR__) . '/db.php';
    }
    loadEnv();

    $raw = $_ENV['PUSH_DIAGNOSTIC_DRY_RUN'] ?? getenv('PUSH_DIAGNOSTIC_DRY_RUN');
    if ($raw === false || $raw === null || $raw === '') {
        return false;
    }

    $s = strtolower(trim((string) $raw));

    return \in_array($s, ['1', 'true', 'yes', 'on'], true);
}

function push_service_vapid_configured(): bool
{
    if (!function_exists('loadEnv')) {
        require_once dirname(__DIR__) . '/db.php';
    }
    loadEnv();

    $pub = env_var('VAPID_PUBLIC_KEY');
    $priv = env_var('VAPID_PRIVATE_KEY');
    $sub = env_var('VAPID_SUBJECT');

    return $pub !== '' && $priv !== '' && $sub !== '';
}

/**
 * @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}}
 *
 * @throws \ErrorException from library validation
 */
function push_service_vapid_auth(): array
{
    if (!function_exists('loadEnv')) {
        require_once dirname(__DIR__) . '/db.php';
    }
    loadEnv();

    return [
        'VAPID' => [
            'subject' => env_var('VAPID_SUBJECT'),
            'publicKey' => env_var('VAPID_PUBLIC_KEY'),
            'privateKey' => env_var('VAPID_PRIVATE_KEY'),
        ],
    ];
}

/**
 * @param list<array<string, mixed>> $rows push_subscriptions rows
 * @param string|null $payloadJson JSON string for notification body (null = no payload / ping-only path where supported)
 *
 * @return array{
 *   ok: bool,
 *   skipped_flush: bool,
 *   reason?: string,
 *   queued: int,
 *   reports: list<array{success: bool, reason: string, endpoint: string, expired: bool, http_status: ?int}>,
 *   error?: string
 * }
 */
function push_service_queue_and_flush(array $rows, ?string $payloadJson = null): array
{
    $empty = [
        'ok' => true,
        'skipped_flush' => true,
        'queued' => 0,
        'reports' => [],
    ];

    if ($rows === []) {
        return $empty + ['reason' => 'no_subscriptions'];
    }

    if (!push_service_vapid_configured()) {
        return [
            'ok' => false,
            'skipped_flush' => true,
            'queued' => 0,
            'reports' => [],
            'error' => 'VAPID keys not configured (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT)',
        ];
    }

    $payload = $payloadJson ?? json_encode(['diagnostic' => true], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return [
            'ok' => false,
            'skipped_flush' => true,
            'queued' => 0,
            'reports' => [],
            'error' => 'payload encoding failed',
        ];
    }

    try {
        $auth = push_service_vapid_auth();
        $webPush = new WebPush($auth, [], 30);
    } catch (\Throwable $e) {
        return [
            'ok' => false,
            'skipped_flush' => true,
            'queued' => 0,
            'reports' => [],
            'error' => $e->getMessage(),
        ];
    }

    $queued = 0;
    try {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $subscription = push_service_subscription_from_row($row);
            $webPush->queueNotification($subscription, $payload);
            $queued++;
        }
    } catch (\Throwable $e) {
        return [
            'ok' => false,
            'skipped_flush' => true,
            'queued' => $queued,
            'reports' => [],
            'error' => $e->getMessage(),
        ];
    }

    $skipNetwork = !push_service_sending_enabled() || push_service_diagnostic_dry_run_only();

    if ($skipNetwork) {
        return [
            'ok' => true,
            'skipped_flush' => true,
            'reason' => push_service_diagnostic_dry_run_only()
                ? 'PUSH_DIAGNOSTIC_DRY_RUN enabled (queued locally, no HTTP)'
                : 'PUSH_SENDING_ENABLED is not true (queued locally, no HTTP)',
            'queued' => $queued,
            'reports' => [],
        ];
    }

    $reports = [];
    try {
        foreach ($webPush->flush() as $report) {
            $reports[] = push_service_message_report_to_array($report);
        }
    } catch (\Throwable $e) {
        return [
            'ok' => false,
            'skipped_flush' => false,
            'queued' => $queued,
            'reports' => $reports,
            'error' => $e->getMessage(),
        ];
    }

    $allOk = $reports === [] || !array_filter($reports, static fn (array $r): bool => !$r['success']);

    return [
        'ok' => $allOk,
        'skipped_flush' => false,
        'queued' => $queued,
        'reports' => $reports,
    ];
}

/**
 * @return array{success: bool, reason: string, endpoint: string, expired: bool, http_status: ?int}
 */
function push_service_message_report_to_array(MessageSentReport $report): array
{
    $status = null;
    $resp = $report->getResponse();
    if ($resp !== null) {
        $status = $resp->getStatusCode();
    }

    return [
        'success' => $report->isSuccess(),
        'reason' => $report->getReason(),
        'endpoint' => $report->getEndpoint(),
        'expired' => $report->isSubscriptionExpired(),
        'http_status' => $status,
    ];
}

/**
 * Convenience: load all subscriptions for a user and run queue (+ optional flush).
 *
 * @return array<string, mixed>
 */
function push_service_send_for_user(PDO $pdo, int $userId, ?string $payloadJson = null): array
{
    require_once __DIR__ . '/push_subscription_repository.php';

    if ($userId <= 0) {
        return [
            'ok' => false,
            'skipped_flush' => true,
            'queued' => 0,
            'reports' => [],
            'error' => 'invalid user id',
        ];
    }

    $rows = push_subscription_list_for_user($pdo, $userId);

    return push_service_queue_and_flush($rows, $payloadJson);
}
