<?php
/**
 * HTTP-invoked export worker (cron GET). Secured with EXPORT_WORKER_TOKEN.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/user_export.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (!user_export_worker_token_valid($token)) {
    http_response_code(403);
    echo 'Forbidden';

    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(503);
    echo 'Unavailable';

    exit;
}

try {
    $job = user_export_claim_next_job($pdo);
    if ($job === null) {
        echo 'noop';

        exit;
    }

    user_export_build_and_finalize($pdo, $job);
    $st = $pdo->prepare('SELECT status FROM user_data_exports WHERE id = ? LIMIT 1');
    $st->execute([(int) $job['id']]);
    $final = $st->fetchColumn();
    echo ($final === 'ready') ? 'ok' : 'failed';
} catch (Throwable $e) {
    error_log('export_worker: ' . $e->getMessage());
    http_response_code(500);
    echo 'error';
}
