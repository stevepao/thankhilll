<?php
/**
 * GET /push/vapid-public-key — Public VAPID key for PushManager.subscribe() (read-only).
 *
 * Also reachable as /push/vapid-public-key.php if URL rewriting is unavailable.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

loadEnv();

$publicKey = trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''));

if ($publicKey === '') {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'vapid_not_configured']);
    exit;
}

$format = isset($_GET['format']) ? (string) $_GET['format'] : '';
if ($format === 'text' || $format === 'plain') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=86400');
    echo $publicKey;
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
echo json_encode(['publicKey' => $publicKey], JSON_UNESCAPED_SLASHES);
