#!/usr/bin/env php
<?php
/**
 * bin/reset_migration_history_to_baseline.php
 *
 * Clears migration bookkeeping and records only the collapsed baseline as applied.
 * Does NOT modify application tables or user data.
 *
 * Use when your database schema already matches migrations/001_baseline.sql (typical
 * for a dev DB that ran the old incremental migrations) so future `php bin/migrate.php`
 * runs match fresh tester installs.
 *
 * Usage:
 *   php bin/reset_migration_history_to_baseline.php --yes
 *
 * Safety: refuses without --yes (prevents accidental runs).
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const BASELINE_MIGRATION_FILENAME = '001_baseline.sql';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

if (!in_array('--yes', $argv, true)) {
    fwrite(
        STDERR,
        "This clears `migrations` rows and inserts only \"" . BASELINE_MIGRATION_FILENAME . "\" as applied.\n"
        . "Application data is not deleted. If your schema is behind the baseline, run `php bin/migrate.php` first "
        . "or fix drift manually.\n\n"
        . "Re-run with: php bin/reset_migration_history_to_baseline.php --yes\n"
    );
    exit(1);
}

$baselinePath = realpath(__DIR__ . '/../migrations/' . BASELINE_MIGRATION_FILENAME);
if ($baselinePath === false || !is_readable($baselinePath)) {
    fwrite(STDERR, 'Baseline file missing or unreadable: migrations/' . BASELINE_MIGRATION_FILENAME . "\n");
    exit(1);
}

try {
    $pdo = db();
    echo "Connected to database.\n";

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec('DELETE FROM migrations');
    $ins = $pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
    $ins->execute(['filename' => BASELINE_MIGRATION_FILENAME]);

    echo 'Migration history reset: only "' . BASELINE_MIGRATION_FILENAME . "\" is recorded as applied.\n";
    echo "Next: add new migrations as 002_*.sql, 003_*.sql, … and run php bin/migrate.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
