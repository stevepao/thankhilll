# Timezones and stored times

Thankhill treats database timestamps consistently so users see correct dates and intervals regardless of where PHP’s **default timezone** or the host’s **system timezone** are set. This document is the **contract** for new code and a **checklist** when auditing changes.

---

## Contract

### MySQL session is UTC

After each PDO connection, **`db.php`** runs:

```sql
SET SESSION time_zone = '+00:00';
```

So **`NOW()`**, **`CURRENT_TIMESTAMP`**, and columns that default to them store **UTC wall-clock** values in **`DATETIME`** fields (unless a migration explicitly documents otherwise).

### Column semantics

| Storage | Meaning | Display / parsing rule |
|--------|---------|-------------------------|
| **`DATETIME`** (with time) | An instant in time, stored as UTC components | Parse as UTC in PHP; convert to the **viewer’s IANA timezone** (`users.timezone`) when showing calendar dates/times in the UI. |
| **`DATE`** (`Y-m-d`, no time of day) | A **journal calendar day** (e.g. “today’s gratitude”), not a UTC instant | Format for labels without tying the string to the server’s default timezone—see **`user_mysql_date_only_label()`**. |

### PHP default timezone

PHP’s **`date_default_timezone_get()`** must **not** be used to infer what a naive MySQL string “means.” Hosts often use `America/New_York` (or `UTC`). **`strtotime($mysqlDatetime)`** on a timezone-less string interprets it in **PHP’s default zone**, not UTC—so it **does not** match our DB contract and will shift labels and comparisons (often by several hours).

---

## Helpers (`includes/user_timezone.php`)

Use these instead of raw **`strtotime()`** / **`gmdate()`** on values from the database:

| Function | Use when |
|----------|----------|
| **`user_datetime_immutable_utc(string $mysqlDatetime): ?DateTimeImmutable`** | You need the instant (`DATETIME` stored as UTC). |
| **`user_mysql_utc_label(?string $mysqlDatetime): string`** | Fixed **`Y-m-d H:i UTC`** label (e.g. data export audit lines). |
| **`user_mysql_date_only_label(?string $mysqlDate): string`** | **`DATE`** columns → human **`M j, Y`** without server-TZ drift. |
| **`user_timezone_get(PDO $pdo, int $userId): string`** | Viewer’s IANA zone for “local” formatting. |
| **`datetime_attr_utc_mysql(string $mysqlUtc): string`** | HTML **`datetime`** attributes for UTC **`DATETIME`** values (`…Z`). |

Typical UI pattern for a UTC **`DATETIME`** shown as a local calendar date:

1. **`$dt = user_datetime_immutable_utc($row['created_at']);`**
2. **`$dt->setTimezone(new DateTimeZone(user_timezone_get($pdo, $userId)))->format('M j, Y')`** (or include time if needed).

For **interval math** (throttles, expiry checks), use **`$dt->getTimestamp()`** compared to **`time()`** after parsing with **`user_datetime_immutable_utc()`**.

---

## Audit checklist (code review / periodic pass)

When touching persistence or display of times:

1. **Grep** application **`*.php`** for **`strtotime(`** — there should be **no** uses on MySQL **`DATETIME`** / **`TIMESTAMP`** strings. (Comments may mention it.)
2. **New queries** — Prefer **`UTC_TIMESTAMP()`** (or session-backed **`NOW()`** with UTC session) for writes; document any exception.
3. **JSON / MCP / exports** — Prefer explicit **`Z`** ISO strings or documented UTC fields; align with **`user_export.php`** / MCP tools that already use **`DateTimeImmutable(..., UTC)`**.
4. **`DATE` vs `DATETIME`** — Do not mix “journal day” **`DATE`** handling with instant arithmetic without an explicit product rule.
5. **Cron / reminders** — Uses **`user_local_today_ymd()`** and related helpers; keep “whose today” defined by **`users.timezone`**, not server local midnight alone.
6. **Legacy data** — If an environment ever wrote **`DATETIME`** before **`SET SESSION time_zone = '+00:00'`**, see **`bin/fix_historical_mysql_datetimes_to_utc.php`** and comments in **`db.php`**.

---

## Related files

- **`db.php`** — PDO connect + **`SET SESSION time_zone = '+00:00'`**
- **`includes/user_timezone.php`** — Helpers above
- **`bin/fix_historical_mysql_datetimes_to_utc.php`** — One-time repair when historical rows were stored with a non-UTC session

Broader security topics (CSRF, sessions, media signing, cron secrets): **`docs/SECURITY.md`**.

Keeping this contract avoids subtle bugs (wrong calendar day on invitations, incorrect OTP resend windows, misleading “UTC” labels on exports, etc.) and keeps behavior stable across hosting regions.
