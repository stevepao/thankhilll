# Data ownership and export

Why this doc exists: operators and contributors need one place that matches **what the export actually builds** (`includes/user_export.php`) and how that reflects ownership and privacy.

## What counts as user-owned data (export scope)

- **Your notes** you authored (`notes.user_id` = you): calendar **`entry_date`**, timestamps, **thoughts** on those notes, **photos** you attached, **group names** the note was shared with, plus **comments and reactions others left on your notes** (their **`display_name`** plus text or emoji).
- **Comments you wrote** on someone else’s shared notes: exported separately as **`my_comments.json`** with context (**who owns the parent note**, **`note_created_at`**, your comment text). Their note body and their photos are **not** copied.

## What is excluded from export

- **Internal IDs** (numeric user ids, note ids, etc.) do not appear in JSON payloads.
- **Email addresses** are not written into export files (README and metadata match `user_export_readme_text()`).
- **Other people’s private journals**: you only get **your** notes and media, plus **`my_comments.json`** as above.
- **System-only data**: sessions, refresh tokens, MCP bearer hashes, raw OTP hashes, push subscription endpoints, etc., are out of scope for the personal ZIP.

## User content vs other people’s content (short definitions)

- **User content (yours)**: notes you own, thoughts and photos on those notes, and comments you post anywhere you are allowed to.
- **Other people’s content**: notes they own, their photos, their thoughts. You may **see** some of it in the app when shared in a group, but the export **does not bulk-copy** their bodies or binaries except where they interacted **on your** note (comments/reactions listed under your note in **`notes.json`**).

## Why `my_comments.json` omits their note bodies and photos

Those entries belong to another author. The product gives you a **record of what you wrote** and minimal context (**note author display name**, **note created_at**) so you can recognize the thread without cloning their journal into your archive.

## Export ZIP layout (schema overview)

Inside the dated folder (for example `thankhill-export-YYYY-MM-DD/`):

| Path | Role |
|------|------|
| **`README.txt`** | Human summary, counts, privacy reminders |
| **`metadata.json`** | App name, **`export_version`**, **`generated_at`** (ISO UTC **`Z`**), counts |
| **`notes.json`** | **`user.display_name`**, array **`notes`** with **`created_at`**, **`shared_with_groups`**, **`thoughts`**, **`comments`**, **`reactions`**, **`photos`** (each photo references a **`file_name`**) |
| **`my_comments.json`** | **`user.display_name`**, **`comments`** list as described above |
| **`photos/`** | JPEG/PNG files copied from your notes only |

Names everywhere are **display names** only, as they appear in Thankhill.

## Async export flow

1. User requests export on **Me** (`user_export_enqueue()`): row **`queued`** if no active **`queued`/`running`** job for that user.
2. **`export_worker.php`** runs via HTTP GET with **`EXPORT_WORKER_TOKEN`** (see **`docs/BACKGROUND_JOBS_AND_HOSTING.md`**). Worker **`claim_next_job`**: **`queued` → `running`**, builds ZIP, then **`ready`** or **`failed`**.
3. **`user_export_send_ready_email()`** notifies if a notification email exists; body links to **Me**, **not** a direct file URL (`includes/user_export.php`).
4. User downloads from **Me** while signed in (`me_export_download.php`). User may **delete** a ready export (status **`deleted_by_user`**); file is removed from disk.

## Links and `APP_BASE_URL`

User-facing absolute links (email to Me, push helpers, MCP signing base) use **`app_absolute_url()`** in **`includes/app_url.php`**: prefer **`APP_BASE_URL`** in `.env` (no trailing slash). When unset, the origin is inferred from the current HTTP request. CLI scripts that emit URLs should set **`APP_BASE_URL`** (see **`bin/send_daily_gratitude_reminders.php`** header comment).

Debug-only alternate: some CLI tests use **`THANKHILL_BASE_URL`** (example in **`debug/push_endpoints_cli_test.php`**) for hitting a running web stack. Production conventions remain **`APP_BASE_URL`**.

## Related code

- **`includes/user_export.php`** (ZIP builder, payloads, prune, worker token)
- **`export_worker.php`**, **`me_export_download.php`**, **`me.php`** (UI)
