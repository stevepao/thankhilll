# Background jobs and hosting

Why this doc exists: Thankhill runs on **plain PHP** and common shared hosts (example: **IONOS**) where you often **cannot** rely on long-lived workers. Scheduled HTTP GET is the practical trigger.

## Constraint: HTTP GET cron (not mandatory CLI)

- **`cron_daily_gratitude_reminders.php`**: documented for hosts that only allow URL cron. **`CRON_SECRET`** query parameter must match ( **`hash_equals`** ).
- **`export_worker.php`**: same pattern for export processing; gated by **`EXPORT_WORKER_TOKEN`** (`user_export_worker_token_valid()`).

CLI alternatives exist where appropriate (example: **`bin/send_daily_gratitude_reminders.php`**). Operators pick what the host allows.

## Worker endpoint hardening

- **No anonymous export processing**: wrong or missing token yields **403** plain **`Forbidden`** (`export_worker.php`).
- Treat URLs like **`…/export_worker.php?token=EXPORT_WORKER_TOKEN`** as **secrets** in cron panels (same hygiene as **`CRON_SECRET`**).

## Job queue semantics (exports)

Implemented in **`includes/user_export.php`**:

| Status | Meaning |
|--------|---------|
| **`queued`** | Waiting for worker |
| **`running`** | Worker claimed row and set **`started_at`** |
| **`ready`** | ZIP path recorded; user can download |
| **`failed`** | **`error_message`** set; user may retry after fixing cause |
| **`deleted_by_user`** | User removed file; row kept for history |

- **One active job per user**: enqueue refuses if another **`queued`** or **`running`** exists for that **`user_id`**.
- **One job per worker invocation**: **`user_export_claim_next_job()`** locks and promotes **at most one** **`queued`** row per call.
- **Stale `running`**: **`user_export_fail_stuck_jobs()`** marks **`running`** rows older than **`USER_EXPORT_STUCK_MINUTES`** (default 45) as **`failed`** with a timeout message before picking new work.

## UI expectation

Copy on **Me** tells users exports run when the site’s scheduled job runs and may take **hours** on some hosts. Email points to **Me** via **`app_absolute_url('/me.php')`**, not a direct ZIP link.

## Link construction (`APP_BASE_URL`)

- **`includes/app_url.php`** **`app_absolute_url($path)`**: uses **`APP_BASE_URL`** when set; otherwise derives scheme/host from the request.
- Set **`APP_BASE_URL`** in `.env` for **CLI** jobs that compose URLs (reminders) so links are not **`http://localhost`**.

## Failure modes and recovery

- **Worker never runs**: rows stay **`queued`** until cron is fixed.
- **Host kills PHP mid-run**: row may stay **`running`** until **`USER_EXPORT_STUCK_MINUTES`** passes, then **`failed`** with timeout text; user requests again.
- **ZIP build throws**: **`failed`** with trimmed exception message logged server-side (`error_log`).
- **Retention**: **`user_export_prune_old_exports()`** removes oldest finished **`ready`/`failed`** archives beyond **`USER_EXPORT_RETENTION`** per user ( **`deleted_by_user`** rows typically have no file path).

## Related files

- **`export_worker.php`**, **`cron_daily_gratitude_reminders.php`**, **`includes/user_export.php`**, **`.env.example`**
