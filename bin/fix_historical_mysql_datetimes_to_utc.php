#!/usr/bin/env php
<?php
/**
 * bin/fix_historical_mysql_datetimes_to_utc.php
 *
 * One-time repair for DATETIME columns written when MySQL @@session.time_zone
 * was not UTC (e.g. server default America/New_York) while the app assumed UTC
 * when displaying (see db.php SET SESSION time_zone = '+00:00').
 *
 * For each non-null value: interpret the stored string as local wall time in
 * --source-tz, convert to a UTC Y-m-d H:i:s, UPDATE in place.
 *
 * Safety: rows that already look like post-deploy UTC inserts can be skipped by
 * passing --deploy-utc=... (ISO 8601 instant in UTC). Any row whose stored
 * string parses as UTC *before* that instant is migrated; rows at/after that
 * instant are skipped (we assume they were written after SET SESSION time_zone).
 *
 * Usage:
 *   php bin/fix_historical_mysql_datetimes_to_utc.php --dry-run
 *   php bin/fix_historical_mysql_datetimes_to_utc.php --apply --deploy-utc="2026-05-03T18:00:00Z"
 *
 * Destructive alternative (migrates every row; will corrupt rows already stored as UTC):
 *   php bin/fix_historical_mysql_datetimes_to_utc.php --apply --convert-all
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/user_timezone.php';

/**
 * @return list<array{0:string,1:string,2:list<string>}>
 */
function th_datetime_migration_targets(): array
{
    return [
        ['users', 'id', ['created_at']],
        ['auth_identities', 'id', ['created_at', 'last_used_at']],
        ['groups', 'id', ['created_at']],
        ['group_members', 'id', ['joined_at']],
        ['group_invitations', 'id', ['accepted_at', 'declined_at', 'created_at', 'expires_at']],
        ['group_invite_requests', 'id', ['created_at', 'approved_at', 'declined_at']],
        ['notes', 'id', ['created_at', 'updated_at']],
        ['reactions', 'id', ['created_at']],
        ['notification_tokens', 'id', ['created_at', 'last_used_at']],
        ['email_login_otps', 'id', ['expires_at', 'consumed_at', 'last_sent_at', 'created_at']],
        ['note_media', 'id', ['created_at']],
        ['note_thoughts', 'id', ['created_at']],
        ['thought_reactions', 'id', ['created_at']],
        ['thought_comments', 'id', ['created_at']],
        ['push_subscriptions', 'id', ['last_used_at', 'created_at', 'updated_at']],
        ['user_notification_prefs', 'user_id', ['created_at', 'updated_at']],
        ['auth_refresh_tokens', 'id', ['expires_at', 'created_at']],
        ['mcp_access_tokens', 'id', ['created_at', 'expires_at', 'revoked_at']],
    ];
}

function th_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $st->execute([$table]);

    return (bool) $st->fetchColumn();
}

/** Interpret legacy MySQL string as local wall time in $sourceTz, return UTC storage string. */
function th_legacy_string_to_utc_string(string $mysqlDatetime, DateTimeZone $sourceTz): ?string
{
    $mysqlDatetime = trim($mysqlDatetime);
    if ($mysqlDatetime === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($mysqlDatetime, $sourceTz))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function th_parse_iso_utc(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw);
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));

        return $utc;
    } catch (Throwable) {
        return null;
    }
}

function th_print_usage(): void
{
    $self = basename(__FILE__);
    fwrite(STDERR, <<<TXT
Usage:
  php {$self} [--dry-run] [--apply] [--source-tz=America/New_York]
              [--deploy-utc=ISO8601] [--convert-all]

  --dry-run     Print planned changes only (default if --apply omitted).
  --apply       Execute UPDATEs (requires --deploy-utc or --convert-all).
  --source-tz   MySQL session zone used for NOW() before the fix (default: America/New_York).
  --deploy-utc  Skip rows whose stored value parses as UTC at or after this instant
                (set to when db.php SET SESSION time_zone was deployed).
  --convert-all Migrate every non-null datetime (dangerous if some rows are already UTC).

Environment:
  TH_LEGACY_MYSQL_SESSION_TZ — default for --source-tz if flag omitted.

TXT);
}

$apply = false;
$dryRun = true;
$convertAll = false;
$sourceTzArg = null;
$deployUtcArg = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        th_print_usage();
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        $dryRun = false;
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        $apply = false;
        continue;
    }
    if ($arg === '--convert-all') {
        $convertAll = true;
        continue;
    }
    if (str_starts_with($arg, '--source-tz=')) {
        $sourceTzArg = substr($arg, strlen('--source-tz='));
        continue;
    }
    if (str_starts_with($arg, '--deploy-utc=')) {
        $deployUtcArg = substr($arg, strlen('--deploy-utc='));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    th_print_usage();
    exit(2);
}

if ($apply && !$convertAll && ($deployUtcArg === null || trim((string) $deployUtcArg) === '')) {
    fwrite(STDERR, "Error: --apply requires --deploy-utc=... (recommended) or --convert-all (risky).\n");
    exit(2);
}

$tzName = $sourceTzArg !== null && trim($sourceTzArg) !== ''
    ? trim($sourceTzArg)
    : env_var('TH_LEGACY_MYSQL_SESSION_TZ', 'America/New_York');

try {
    $sourceTz = new DateTimeZone(user_timezone_normalize($tzName));
} catch (Throwable $e) {
    fwrite(STDERR, "Invalid --source-tz / TH_LEGACY_MYSQL_SESSION_TZ: {$tzName}\n");
    exit(2);
}

$deployCutoff = null;
if ($deployUtcArg !== null && trim($deployUtcArg) !== '') {
    $deployCutoff = th_parse_iso_utc(trim($deployUtcArg, " \t\"'"));
    if ($deployCutoff === null) {
        fwrite(STDERR, "Invalid --deploy-utc (use ISO 8601, e.g. 2026-05-03T18:00:00Z).\n");
        exit(2);
    }
}

$pdo = db();
echo $dryRun ? "Dry run (no writes).\n" : "Applying updates.\n";
echo "Source zone (legacy NOW): {$sourceTz->getName()}\n";
if ($deployCutoff !== null) {
    echo 'Deploy cutoff (skip rows if naive-UTC parse >= this): ' . $deployCutoff->format('Y-m-d H:i:s') . " UTC\n";
}
if ($convertAll) {
    echo "WARNING: --convert-all migrates every row; rows already stored as UTC will be corrupted.\n";
}

$targets = th_datetime_migration_targets();
$totalWouldChange = 0;
$totalSkippedDeploy = 0;
$totalSkippedNoop = 0;
$totalRowsTouched = 0;

foreach ($targets as [$table, $pk, $columns]) {
    if (!th_table_exists($pdo, $table)) {
        echo "Skip table (missing): {$table}\n";
        continue;
    }

    $colList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
    $stmt = $pdo->query("SELECT `{$pk}`, {$colList} FROM `{$table}`");
    if ($stmt === false) {
        echo "Skip table (query failed): {$table}\n";
        continue;
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = $row[$pk] ?? null;
        if ($id === null) {
            continue;
        }

        $updates = [];
        foreach ($columns as $col) {
            $raw = $row[$col] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $raw = (string) $raw;

            if (!$convertAll && $deployCutoff !== null) {
                $naiveUtc = user_datetime_immutable_utc($raw);
                if ($naiveUtc !== null && $naiveUtc >= $deployCutoff) {
                    $totalSkippedDeploy++;
                    continue;
                }
            }

            $newVal = th_legacy_string_to_utc_string($raw, $sourceTz);
            if ($newVal === null) {
                fwrite(STDERR, "WARN: could not parse {$table}.{$col} id={$id}\n");
                continue;
            }
            if ($newVal === $raw) {
                $totalSkippedNoop++;
                continue;
            }

            $updates[$col] = $newVal;
        }

        if ($updates === []) {
            continue;
        }

        $totalWouldChange += count($updates);
        $totalRowsTouched++;

        if ($dryRun) {
            echo "[{$table}] {$pk}={$id} " . json_encode($updates, JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }

        $sets = [];
        $params = [];
        foreach ($updates as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $sql = 'UPDATE `' . str_replace('`', '``', $table) . '` SET ' . implode(', ', $sets) . " WHERE `{$pk}` = ?";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    }
}

echo "\nSummary:\n";
echo "  Column values to change: {$totalWouldChange}\n";
echo "  Rows touched: {$totalRowsTouched}\n";
echo "  Skipped (deploy cutoff, naive UTC >= deploy): {$totalSkippedDeploy}\n";
echo "  Skipped (already UTC string match): {$totalSkippedNoop}\n";

if ($dryRun) {
    echo "\nRe-run with --apply --deploy-utc=... to write changes.\n";
}
