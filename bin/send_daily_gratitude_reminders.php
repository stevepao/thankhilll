#!/usr/bin/env php
<?php
/**
 * Hourly cron: send at most one evening gratitude reminder per user per local calendar day.
 *
 * Schedule (example — run once per hour, not per user):
 *   0 * * * * php /path/to/app/bin/send_daily_gratitude_reminders.php
 *
 * IONOS / HTTP cron: use cron_daily_gratitude_reminders.php?token=YOUR_CRON_SECRET instead.
 *
 * Requires .env with DB_* and (for real sends) VAPID_* and PUSH_SENDING_ENABLED=1.
 * For notification URLs in payloads, set APP_BASE_URL when running from CLI (no HTTP_HOST).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/daily_gratitude_reminders_run.php';

$result = daily_gratitude_reminders_run_once();
if (!$result['ok']) {
    $msg = 'daily gratitude reminders: ' . ($result['error'] ?? 'failed');
    if (defined('STDERR') && is_resource(STDERR)) {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        error_log($msg);
    }
    exit(1);
}

exit(0);
