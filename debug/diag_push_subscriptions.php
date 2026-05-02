<?php
/**
 * diag_push_subscriptions.php — Validates push_subscriptions schema, uniqueness, and FK CASCADE.
 *
 * Does not send push notifications or use browser APIs.
 * Run from CLI: php debug/diag_push_subscriptions.php
 * Or via HTTPS (remove after use): /debug/diag_push_subscriptions.php
 *
 * Deletes the test user at the end (CASCADE); uses finally to remove the user if a step fails.
 */
declare(strict_types=1);

use Minishlink\WebPush\Subscription;

$root = dirname(__DIR__);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    echo "FAIL: vendor/autoload.php missing. Run composer install from project root.\n";
    exit(1);
}

require_once $root . '/db.php';
require_once $root . '/includes/push_subscription_repository.php';

loadEnv();

/**
 * @param array<int, string> $lines
 */
function out_step(string $label, bool $ok, array $lines = []): void
{
    $status = $ok ? 'PASS' : 'FAIL';
    echo "[{$status}] {$label}\n";
    foreach ($lines as $line) {
        echo "        {$line}\n";
    }
    echo "\n";
}

function schema_validate(PDO $pdo): array
{
    $errors = [];
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($dbName) || $dbName === '') {
        return ['Could not read current database name'];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'push_subscriptions'
        SQL
    );
    $stmt->execute([$dbName]);
    if ((int) $stmt->fetchColumn() !== 1) {
        $errors[] = 'Table push_subscriptions does not exist (run migration 020).';
    }

    $needCols = ['user_id', 'endpoint_hash', 'endpoint_url', 'p256dh', 'auth'];
    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'push_subscriptions'
        SQL
    );
    $stmt->execute([$dbName]);
    $have = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($row['COLUMN_NAME']) && is_string($row['COLUMN_NAME'])) {
            $have[$row['COLUMN_NAME']] = true;
        }
    }
    foreach ($needCols as $c) {
        if (!isset($have[$c])) {
            $errors[] = "Missing column push_subscriptions.{$c}";
        }
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT NON_UNIQUE FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'push_subscriptions'
          AND COLUMN_NAME = 'endpoint_hash' AND NON_UNIQUE = 0
        LIMIT 1
        SQL
    );
    $stmt->execute([$dbName]);
    if ($stmt->fetchColumn() === false) {
        $errors[] = 'No UNIQUE index on push_subscriptions.endpoint_hash';
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = ?
          AND TABLE_NAME = 'push_subscriptions'
          AND CONSTRAINT_NAME = 'fk_push_subscriptions_user'
        LIMIT 1
        SQL
    );
    $stmt->execute([$dbName]);
    $rule = $stmt->fetchColumn();
    if (!is_string($rule) || strtoupper($rule) !== 'CASCADE') {
        $errors[] = 'FK fk_push_subscriptions_user DELETE_RULE is not CASCADE (got: '
            . (is_string($rule) ? $rule : 'none') . ')';
    }

    return $errors;
}

echo "=== push_subscriptions integrity diagnostic ===\n\n";

try {
    $pdo = db();
} catch (Throwable $e) {
    echo 'FAIL: Database connection: ' . $e->getMessage() . "\n";
    exit(1);
}

$schemaErrors = schema_validate($pdo);
out_step(
    'Schema (table, columns, UNIQUE(endpoint_hash), FK CASCADE)',
    $schemaErrors === [],
    $schemaErrors === [] ? ['Checks information_schema'] : $schemaErrors
);

if ($schemaErrors !== []) {
    try {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
        echo "Final push_subscriptions row count: {$n}\n";
    } catch (Throwable) {
        echo "Final push_subscriptions row count: (unavailable)\n";
    }
    exit(1);
}

$userId = null;
$ep1 = 'https://fcm.googleapis.com/fcm/send/diag-' . bin2hex(random_bytes(16));
$ep2 = 'https://push.example.test/v1/diag-' . bin2hex(random_bytes(16));
$b64url = static fn (int $bytes): string => rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
$p256dhA = $b64url(65);
$authA = $b64url(16);
$p256dhB = $b64url(65);
$authB = $b64url(16);

try {
    try {
        $ins = $pdo->prepare(
            'INSERT INTO users (display_name, preferences_json) VALUES (?, NULL)'
        );
        $ins->execute(['diag_push_subscriptions']);
        $userId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        out_step('Create test user', false, [$e->getMessage()]);
        throw $e;
    }

    if ($userId <= 0) {
        out_step('Create test user', false, ['lastInsertId was not positive']);
        throw new RuntimeException('no user id');
    }

    out_step('Create test user', true, ["user_id = {$userId}"]);

    $id1 = push_subscription_upsert($pdo, $userId, $ep1, $p256dhA, $authA, null, 'diag/ua');
    $id2 = push_subscription_upsert($pdo, $userId, $ep2, $p256dhB, $authB, null, null);

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
    $cntStmt->execute([$userId]);
    $cnt2 = (int) $cntStmt->fetchColumn();

    $okTwo = $cnt2 === 2 && $id1 > 0 && $id2 > 0 && $id1 !== $id2;
    out_step(
        'Two distinct endpoints for same user',
        $okTwo,
        $okTwo ? ["count for user_id = {$cnt2}", "subscription ids {$id1}, {$id2}"]
            : ["expected count 2, got {$cnt2}"]
    );

    $p256dhA2 = $b64url(65);
    $authA2 = $b64url(16);
    $id1b = push_subscription_upsert($pdo, $userId, $ep1, $p256dhA2, $authA2, null, 'diag/ua2');

    $cntStmt->execute([$userId]);
    $cnt3 = (int) $cntStmt->fetchColumn();

    $row1 = push_subscription_find_by_endpoint_hash($pdo, push_subscription_endpoint_hash($ep1));
    $dupOk = $cnt3 === 2 && $id1b === $id1 && is_array($row1)
        && (string) ($row1['p256dh'] ?? '') === $p256dhA2;

    out_step(
        'Duplicate endpoint upsert does not add a row (updates same subscription)',
        $dupOk,
        $dupOk
            ? ["count still {$cnt3}", "same id {$id1b}", 'p256dh updated on duplicate']
            : ["count {$cnt3} (expected 2)", "id repeat {$id1} vs {$id1b}"]
    );

    $libSubOk = false;
    $libSubLines = [];
    if (is_array($row1)) {
        try {
            $subObj = Subscription::create([
                'endpoint' => (string) ($row1['endpoint_url'] ?? ''),
                'keys' => [
                    'p256dh' => (string) ($row1['p256dh'] ?? ''),
                    'auth' => (string) ($row1['auth'] ?? ''),
                ],
            ]);
            $libSubOk = $subObj->getEndpoint() === $ep1;
            $libSubLines = $libSubOk ? ['endpoint matches stored URL'] : ['endpoint mismatch'];
        } catch (Throwable $e) {
            $libSubLines = [$e->getMessage()];
        }
    } else {
        $libSubLines = ['expected row for ep1 after upsert'];
    }
    out_step('minishlink Subscription from DB row (opaque keys)', $libSubOk, $libSubLines);

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $existsStmt->execute([$userId]);
    $userGone = (int) $existsStmt->fetchColumn() === 0;

    $h1 = push_subscription_endpoint_hash($ep1);
    $h2 = push_subscription_endpoint_hash($ep2);
    $leftStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash IN (?, ?)'
    );
    $leftStmt->execute([$h1, $h2]);
    $left = (int) $leftStmt->fetchColumn();

    $cascadeOk = $userGone && $left === 0;
    out_step(
        'Delete user removes push_subscriptions via CASCADE',
        $cascadeOk,
        $cascadeOk
            ? ['no rows remain for test endpoint hashes', 'user row gone']
            : [
                'users row still exists: ' . ($userGone ? 'no' : 'yes'),
                "push rows left for test hashes: {$left}",
            ]
    );

    $userId = null;
} catch (Throwable $e) {
    out_step('Unexpected error', false, [$e->getMessage()]);
} finally {
    if ($userId !== null && $userId > 0) {
        try {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            echo "[cleanup] Removed leftover test user_id={$userId}\n\n";
        } catch (Throwable $e) {
            echo '[cleanup] FAILED: ' . $e->getMessage() . "\n\n";
        }
    }
}

$finalCount = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
echo "Final push_subscriptions row count (full table): {$finalCount}\n";
