<?php
/**
 * Shared runner for daily gratitude reminder pushes (CLI bin + optional HTTP cron URL).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';
require_once dirname(__DIR__) . '/includes/PushService.php';
require_once dirname(__DIR__) . '/includes/push_subscription_repository.php';
require_once dirname(__DIR__) . '/includes/app_url.php';

/**
 * Evening window: local clock hour must be >= this value (24-hour), i.e. from 6:00 PM local onward.
 * Intentionally fixed here; no user-facing picker yet.
 */
const DAILY_GRATITUDE_REMINDER_EVENING_LOCAL_HOUR_MIN = 18;

const DAILY_GRATITUDE_REMINDER_TITLE = 'A moment for gratitude';
const DAILY_GRATITUDE_REMINDER_BODY = 'Take a moment to write something you\'re grateful for today.';

/**
 * Start/end of the viewer’s current local calendar day as UTC MySQL DATETIME strings (inclusive).
 *
 * @return array{0: string, 1: string}
 */
function daily_gratitude_reminder_local_day_utc_bounds_mysql(string $viewerTz): array
{
    try {
        $z = new DateTimeZone(user_timezone_normalize($viewerTz));
    } catch (Throwable $e) {
        $z = new DateTimeZone('UTC');
    }

    $todayLocalStart = new DateTimeImmutable('today', $z);
    $todayLocalEnd = $todayLocalStart->setTime(23, 59, 59);
    $utc = new DateTimeZone('UTC');

    return [
        $todayLocalStart->setTimezone($utc)->format('Y-m-d H:i:s'),
        $todayLocalEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function daily_gratitude_reminder_local_hour_now(string $viewerTz): int
{
    try {
        $z = new DateTimeZone(user_timezone_normalize($viewerTz));
    } catch (Throwable $e) {
        $z = new DateTimeZone('UTC');
    }

    return (int) (new DateTimeImmutable('now', $z))->format('G');
}

/**
 * True if the user already saved at least one gratitude moment today (persisted thought row).
 */
function daily_gratitude_reminder_user_has_written_today(PDO $pdo, int $userId, string $viewerTz): bool
{
    if ($userId <= 0) {
        return false;
    }

    [$startUtc, $endUtc] = daily_gratitude_reminder_local_day_utc_bounds_mysql($viewerTz);

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT 1
        FROM note_thoughts t
        INNER JOIN notes n ON n.id = t.note_id
        WHERE n.user_id = ?
          AND t.created_at >= ?
          AND t.created_at <= ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$userId, $startUtc, $endUtc]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Expired / dead subscriptions cleanup (same idea as comment push).
 *
 * @param array<string, mixed> $result push_service_queue_and_flush return shape
 */
function daily_gratitude_reminder_cleanup_failed_endpoints(PDO $pdo, int $userId, array $result): void
{
    if (($result['skipped_flush'] ?? true) === true || empty($result['reports'])) {
        return;
    }

    foreach ($result['reports'] as $rep) {
        if (!is_array($rep) || !empty($rep['success'])) {
            continue;
        }
        $expired = !empty($rep['expired']);
        $status = $rep['http_status'] ?? null;
        $endpoint = (string) ($rep['endpoint'] ?? '');
        if ($endpoint === '') {
            continue;
        }
        if ($expired || $status === 404 || $status === 410) {
            try {
                push_subscription_delete_by_endpoint_for_user($pdo, $userId, $endpoint);
            } catch (Throwable $e) {
                error_log('daily gratitude reminder: cleanup endpoint failed: ' . $e->getMessage());
            }
        }
    }
}

/**
 * True if at least one subscription received the payload successfully.
 *
 * @param array<string, mixed> $result
 */
function daily_gratitude_reminder_any_delivery_success(array $result): bool
{
    foreach ($result['reports'] ?? [] as $rep) {
        if (is_array($rep) && !empty($rep['success'])) {
            return true;
        }
    }

    return false;
}

/**
 * Run one pass over eligible users (hourly cron safe).
 *
 * @return array{ok: bool, error?: string}
 */
function daily_gratitude_reminders_run_once(): array
{
    loadEnv();

    try {
        $pdo = db();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'database connection failed: ' . $e->getMessage()];
    }

    try {
        $stmt = $pdo->query(
            <<<'SQL'
            SELECT id, timezone, last_reminder_sent_at
            FROM users
            WHERE daily_reminder_enabled = 1
            ORDER BY id ASC
            SQL
        );
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($e instanceof PDOException && pdo_error_is_unknown_column($e)) {
            return ['ok' => false, 'error' => 'missing columns — run migrations (021_daily_gratitude_reminder_columns.sql)'];
        }

        return ['ok' => false, 'error' => 'query failed: ' . $e->getMessage()];
    }

    $todayUrl = app_absolute_url('/index.php');
    $payload = json_encode([
        'title' => DAILY_GRATITUDE_REMINDER_TITLE,
        'body' => DAILY_GRATITUDE_REMINDER_BODY,
        'url' => $todayUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'could not encode payload'];
    }

    foreach ($users as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userId = (int) ($row['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        try {
            $tzRaw = $row['timezone'] ?? null;
            $viewerTz = is_string($tzRaw) && $tzRaw !== '' ? $tzRaw : 'UTC';

            if (daily_gratitude_reminder_local_hour_now($viewerTz) < DAILY_GRATITUDE_REMINDER_EVENING_LOCAL_HOUR_MIN) {
                continue;
            }

            $localToday = user_local_today_ymd($viewerTz);
            $lastSent = $row['last_reminder_sent_at'] ?? null;
            if ($lastSent !== null && $lastSent !== '') {
                $lastYmd = is_string($lastSent) ? substr($lastSent, 0, 10) : '';
                if ($lastYmd === $localToday) {
                    continue;
                }
            }

            if (daily_gratitude_reminder_user_has_written_today($pdo, $userId, $viewerTz)) {
                continue;
            }

            $subs = push_subscription_list_for_user($pdo, $userId);
            if ($subs === []) {
                continue;
            }

            $result = push_service_queue_and_flush($subs, $payload);

            daily_gratitude_reminder_cleanup_failed_endpoints($pdo, $userId, $result);

            $sentNetwork = empty($result['skipped_flush']);
            $delivered = $sentNetwork && daily_gratitude_reminder_any_delivery_success($result);

            if (!$delivered) {
                if (!empty($result['error'])) {
                    error_log('daily gratitude reminders: user ' . $userId . ' push error: ' . (string) $result['error']);
                }
                continue;
            }

            $upd = $pdo->prepare('UPDATE users SET last_reminder_sent_at = ? WHERE id = ?');
            $upd->execute([$localToday, $userId]);
        } catch (Throwable $e) {
            error_log('daily gratitude reminders: user ' . $userId . ' exception: ' . $e->getMessage());
        }
    }

    return ['ok' => true];
}
