#!/usr/bin/env php
<?php
/**
 * bin/migrate.php
 *
 * Simple SQL migration runner for this plain PHP project.
 * - Uses db.php for PDO connection (which loads .env via Dotenv)
 * - Creates the migrations table if needed
 * - Applies pending .sql files from /migrations in filename order
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Split SQL content into executable statements.
 * Keeps things simple for typical schema migrations.
 *
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $result = [];

    foreach ($statements as $statement) {
        $trimmed = trim($statement);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }

    return $result;
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
    echo "Ensured migrations table exists.\n";

    $migrationsDir = realpath(__DIR__ . '/../migrations');
    if ($migrationsDir === false || !is_dir($migrationsDir)) {
        throw new RuntimeException('Migrations directory not found: /migrations');
    }

    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    $appliedStmt = $pdo->query('SELECT filename FROM migrations');
    $applied = $appliedStmt->fetchAll(PDO::FETCH_COLUMN);
    $appliedMap = array_fill_keys($applied, true);

    $appliedCount = 0;

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        if (isset($appliedMap[$filename])) {
            echo "Skipping {$filename} (already applied).\n";
            continue;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new RuntimeException("Unable to read migration: {$filename}");
        }

        echo "Applying {$filename}...\n";
        $pdo->beginTransaction();

        try {
            foreach (splitSqlStatements($sql) as $statement) {
                $pdo->exec($statement);
            }

            $insert = $pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
            $insert->execute(['filename' => $filename]);

            $pdo->commit();
            $appliedCount++;
            echo "Applied {$filename}.\n";
        } catch (Throwable $e) {
            // MySQL DDL can auto-commit; only rollback if a transaction is still active.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException("Migration failed for {$filename}: " . $e->getMessage(), 0, $e);
        }
    }

    if ($appliedCount === 0) {
        echo "No pending migrations.\n";
    } else {
        echo "Done. Applied {$appliedCount} migration(s).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
