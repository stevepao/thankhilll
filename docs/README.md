# Developer documentation index

Short guides grounded in this repo’s PHP layout and `.env` patterns. Read **`README.md`** at the project root for setup; use this folder for contracts and audits.

| Doc | Topic |
|-----|--------|
| **`TIMEZONE.md`** | UTC **`DATETIME`** contract, helpers in **`includes/user_timezone.php`**, audit checklist |
| **`TIMEZONE_AUDIT.md`** | Pointer to **`TIMEZONE.md`** (single source of truth) |
| **`SECURITY.md`** | CSRF, sessions, media signing, cron tokens, MCP bearer storage |
| **`SECURITY_AUDIT.md`** | Practical audit notes: MCP headers/JSON, logging, export download |
| **`DATA_OWNERSHIP_AND_EXPORT.md`** | What exports include/exclude, ZIP layout, **`APP_BASE_URL`** |
| **`PRIVACY_AND_SHARING_MODEL.md`** | Private vs group-shared, export boundaries, principles |
| **`BACKGROUND_JOBS_AND_HOSTING.md`** | HTTP cron workers, export queue, stuck jobs, **`APP_BASE_URL`** |
| **`DATA_MODEL_AND_TERMS.md`** | Glossary: notes, thoughts, comments, reactions, groups |
| **`NON_GOALS.md`** | What the product intentionally avoids |

**Also:** **`internal/README.md`** (MCP token HTTP routes), **`CHANGELOG.md`**, **`migrations/`**.

### Env URL conventions

- **`APP_BASE_URL`**: canonical site origin for **`app_absolute_url()`**, signed MCP media base, reminders when CLI has no host (see **`.env.example`**).
- **`THANKHILL_BASE_URL`**: used only in select **debug** CLI tests (example **`debug/push_endpoints_cli_test.php`**) to hit a running server; prefer **`APP_BASE_URL`** for product paths.

### Product intent (repo-only)

This repo does not ship **`Building a Gratitude App I Wanted to Use.docx`**. Intent reflected here: **mobile-first** browser journal (**README.md**), **plain PHP** plus MySQL, pragmatic hosting (cron GET), small-group sharing, data export for user ownership.
