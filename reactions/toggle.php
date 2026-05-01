<?php
/**
 * reactions/toggle.php — Toggle emoji reaction on a visible thought.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/note_access.php';
require_once dirname(__DIR__) . '/includes/thought_reactions.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$userId = require_login();
csrf_verify_post_or_abort();
$pdo = db();

$thoughtId = (int) ($_POST['thought_id'] ?? 0);
$emojiRaw = $_POST['emoji'] ?? null;

$v = thought_reaction_validate_emoji($emojiRaw);
if (!$v['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $v['error']]);
    exit;
}

if (!user_can_view_thought($pdo, $userId, $thoughtId)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Thought not available.']);
    exit;
}

$result = thought_reaction_toggle($pdo, $thoughtId, $userId, $v['value']);
if (!$result['ok']) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

echo json_encode([
    'ok' => true,
    'thought_id' => $thoughtId,
    'reactions' => $result['reactions'],
]);
