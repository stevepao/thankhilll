<?php
/**
 * Viewer time zone: DB timestamps are UTC; grouping and labels use users.timezone (IANA).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

function user_timezone_list_identifiers(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $ids = DateTimeZone::listIdentifiers();
    $map = [];
    foreach ($ids as $id) {
        $map[$id] = true;
    }

    return $map;
}

/**
 * Normalize stored/submitted zone id; unknown values fall back to UTC.
 */
function user_timezone_normalize(?string $raw): string
{
    $t = trim((string) $raw);
    if ($t === '') {
        return 'UTC';
    }
    if (isset(user_timezone_list_identifiers()[$t])) {
        return $t;
    }
    try {
        new DateTimeZone($t);

        return $t;
    } catch (Throwable $e) {
        return 'UTC';
    }
}

function user_timezone_get(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'UTC';
    }
    try {
        $stmt = $pdo->prepare('SELECT timezone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $col = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_column($e)) {
            throw $e;
        }

        return 'UTC';
    }

    return user_timezone_normalize(is_string($col) ? $col : null);
}

function user_timezone_save(PDO $pdo, int $userId, string $ianaOrOffset): void
{
    if ($userId <= 0) {
        return;
    }
    $z = user_timezone_normalize($ianaOrOffset);
    try {
        $stmt = $pdo->prepare('UPDATE users SET timezone = ? WHERE id = ?');
        $stmt->execute([$z, $userId]);
    } catch (PDOException $e) {
        if (!pdo_error_is_unknown_column($e)) {
            throw $e;
        }
        // Column absent until migration 014 runs; browser probe is a no-op.
    }
}

function user_local_today_ymd(string $ianaTz): string
{
    $z = new DateTimeZone(user_timezone_normalize($ianaTz));

    return (new DateTimeImmutable('now', $z))->format('Y-m-d');
}

function user_local_week_bounds_ymd(string $ianaTz): array
{
    $z = new DateTimeZone(user_timezone_normalize($ianaTz));
    $today = new DateTimeImmutable('today', $z);
    $dow = (int) $today->format('N');
    $monday = $today->modify('-' . ($dow - 1) . ' days');
    $sunday = $monday->modify('+6 days');

    return [$monday->format('Y-m-d'), $sunday->format('Y-m-d')];
}

function user_local_calendar_month_bounds_ymd(string $ianaTz): array
{
    $z = new DateTimeZone(user_timezone_normalize($ianaTz));
    $anchor = new DateTimeImmutable('now', $z);
    $first = $anchor->modify('first day of this month')->setTime(0, 0, 0);
    $last = $anchor->modify('last day of this month')->setTime(0, 0, 0);

    return [$first->format('Y-m-d'), $last->format('Y-m-d')];
}

function user_local_month_start_ymd(string $ianaTz): string
{
    $z = new DateTimeZone(user_timezone_normalize($ianaTz));
    $now = new DateTimeImmutable('now', $z);

    return $now->modify('first day of this month')->format('Y-m-d');
}

/**
 * Parse MySQL DATETIME/TIMESTAMP string as UTC instant.
 */
function user_datetime_immutable_utc(string $mysqlDatetime): ?DateTimeImmutable
{
    $mysqlDatetime = trim($mysqlDatetime);
    if ($mysqlDatetime === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Human-readable label for a MySQL DATETIME stored in UTC (e.g. export timestamps).
 * Avoid strtotime() alone — it assumes the server default zone, not UTC.
 */
function user_mysql_utc_label(?string $mysqlDatetime): string
{
    $mysqlDatetime = trim((string) $mysqlDatetime);
    if ($mysqlDatetime === '') {
        return '';
    }
    $dt = user_datetime_immutable_utc($mysqlDatetime);

    return $dt === null ? $mysqlDatetime : $dt->format('Y-m-d H:i') . ' UTC';
}

/**
 * Format MySQL DATE ('Y-m-d') for display without tying it to the server's default timezone.
 */
function user_mysql_date_only_label(?string $mysqlDate): string
{
    $mysqlDate = trim((string) $mysqlDate);
    if ($mysqlDate === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $mysqlDate, new DateTimeZone('UTC'));
    if ($dt === false) {
        return $mysqlDate;
    }

    return $dt->format('M j, Y');
}

function datetime_attr_utc_mysql(string $mysqlUtc): string
{
    $dt = user_datetime_immutable_utc($mysqlUtc);

    return $dt === null ? '' : $dt->format('Y-m-d\TH:i:s\Z');
}
