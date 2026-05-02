<?php
/**
 * HTTP entry for hosts that only allow URL cron (e.g. IONOS): GET with secret token.
 *
 * Add to .env:
 *   CRON_SECRET=long-random-string
 *
 * Cron URL (replace host and token):
 *   https://your-domain.example/cron_daily_gratitude_reminders.php?token=YOUR_CRON_SECRET
 *
 * Without a valid token the response is 403. Response body is plain text "OK" or "Error".
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

loadEnv();

header('Content-Type: text/plain; charset=UTF-8');

$secret = env_var('CRON_SECRET');
$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/includes/daily_gratitude_reminders_run.php';

$result = daily_gratitude_reminders_run_once();
if (!$result['ok']) {
    http_response_code(500);
    error_log('cron_daily_gratitude_reminders: ' . ($result['error'] ?? 'failed'));
    echo 'Error';
    exit;
}

echo 'OK';
