# Thankhill

**Thankhill** is a mobile-first **gratitude journal** you use in the browser (and can install like an app). It is built and operated by **Hillwork, LLC** for adults **18 and older**.

**Repository:** [github.com/stevepao/thankhilll](https://github.com/stevepao/thankhilll) (`git clone https://github.com/stevepao/thankhilll.git`)

---

## What you can do

- **Today** — Write your gratitude entry for **today** (one main note per calendar day in your timezone). Add **timestamped thoughts** as the day goes on, attach **photos**, and choose whether today’s entry is **private** or shared with **groups** you belong to. You can also see **shared entries from others** on Today when that option is enabled.
- **Notes** — Browse your journal **newest first**. Switch between **your notes** and **everything you can see** (including notes shared in groups). Open any note to read it in full with photos.
- **Note detail** — On **shared** notes, **react** to thoughts and **reply in threads** when comments are available—so small groups can cheer each other on without losing the private journaling feel.
- **Groups** — Create groups, **invite** people, and share chosen entries so only members see them. Accept invitations and manage memberships from the Groups area.
- **Me** — Update your **display name**, **preferences** (default sharing, what appears on Today and in Notes), **notification settings**, **timezone**, and **sign out**. You can **delete your account** permanently here (see the in-app Privacy Policy for what is removed).

### Signing in

- **Google** — Sign in with Google (OpenID Connect).
- **Email** — Request a **one-time code** sent by email (SMTP must be configured on the server).

Sessions use server-side security (idle timeout and optional bounded “stay signed in” via an HttpOnly cookie—no long-lived tokens in browser storage for auth).

### Installable (PWA)

The UI includes a web app manifest and is tuned for phones; on supported browsers you can **add Thankhill to your home screen** for an app-like experience.

### Legal & transparency

Public pages (paths depend on your server rewrite rules):

- **Privacy Policy** — `/policy`
- **Terms of Use** — `/terms`

---

## Alpha testing

This README reflects the product **as of early development / alpha**. Features and wording may change. There is **no obligation** to retain data from alpha builds—plan accordingly for demos and feedback rounds.

**Release notes:** see **`CHANGELOG.md`** (first packaged alpha: **v0.5.0**, 2026-05-01).

---

## For developers and operators

### Stack

- **PHP** 8.0+ (8.2+ recommended if you use Web Push libraries as configured in this repo), **PDO MySQL**, **Composer**
- **MySQL** (MariaDB-compatible setups often work; production examples include IONOS MySQL)

### Setup

1. **Dependencies**

   ```bash
   composer install
   ```

   **Notes screen Tailwind CSS** — `notes.php` loads `/public/tailwind.css`. Keep **Composer-only on production**: deploy with **`public/tailwind.css`** included (committed or baked into your release artifact). **Do not install Node/npm on the app server** for Tailwind; it is build tooling only.

   Regenerate that file on your machine or CI after editing Tailwind markup (`notes.php`, `includes/note_library_card.php`, etc.) or theme tokens (`tailwind.config.js`, `resources/css/tailwind-input.css`):

   ```bash
   npm install
   npm run build:css
   ```

   Use `npm run watch:css` during UI tweaks.

2. **Environment**

   ```bash
   cp .env.example .env
   ```

   Fill in at least **database** credentials. For real sign-in you need **Google OIDC** (optional if you only test email) and/or **SMTP** for email codes. See `.env.example` for **Web Push (VAPID)**, **cron reminders**, **session cookie** overrides, and optional **`NOTE_MEDIA_STORAGE_PATH`** for uploads outside the web root.

3. **Database**

   ```bash
   php bin/migrate.php
   ```

4. **Local server**

   ```bash
   php -S localhost:8000
   ```

   Open `http://localhost:8000`. Use **`localhost` URLs** in Google OAuth redirect URIs when developing.

### URL rewrites (production)

Root **`.htaccess`** is intentionally minimal:

1. **`Authorization`** passthrough into **`HTTP_AUTHORIZATION`** (PHP FastCGI/CGI).
2. Optional legacy rewrite **`/mcp/v1`** → **`mcp/v1.php`** (extensionless URL — not recommended on some shared hosts).

There are **no** other rewrite rules in this file (no mapped **`public/`**, **`internal/`**, etc.). Use direct **`*.php`** URLs or your host’s routing for legal pages, internal tools, and anything else.

**MCP v1 (canonical)** — Configure MCP clients with path **`/mcp/v1.php`** on your host (production: **`https://thank.hillwork.net/mcp/v1.php`**). The concrete **`.php`** URL avoids extensionless routing, content negotiation, and parking-vhost fallbacks on hosts such as IONOS. Implementation: **`mcp/v1.php`** (JSON-RPC handshake, Bearer tokens). Token issuance: **`internal/README.md`** and migration **`002_mcp_access_tokens.sql`** (`php bin/migrate.php`).

### Optional automation

- **Daily gratitude reminders** — Cron-friendly scripts and secrets are described via `.env.example` (`CRON_SECRET`, etc.).
- **Push notifications** — Requires VAPID keys and browser subscription endpoints; see env comments.

### Layout (high level)

| Area | Role |
|------|------|
| `index.php` | Today (daily note + thoughts + media + sharing) |
| `notes.php`, `note.php` | Notes list and note reader |
| `groups.php`, `group.php`, `group_*.php` | Groups and invitations |
| `me.php` | Profile, preferences, notifications, account deletion |
| `login.php`, `auth/` | Sign-in flows |
| `policy.php`, `terms.php` | Legal pages |
| `mcp/v1.php` | MCP JSON-RPC endpoint (**canonical URL** `/mcp/v1.php`); Bearer auth |
| `includes/` | Shared libraries (sessions, auth refresh tokens, push, mailer, MCP token helpers for internal routes, …) |
| `migrations/` | SQL migrations applied by `bin/migrate.php` |

---

## Migrations

Schema is defined by a **single baseline** plus future deltas:

| File | Role |
|------|------|
| **`migrations/001_baseline.sql`** | Full current schema (what shipped before alpha was collapsed here). |
| **`migrations/002_*.sql`, …** | New changes only—add the next free numeric prefix. |

`bin/migrate.php` runs every **`migrations/*.sql`** at the repo root (not `migrations/archive/`), records filenames in the **`migrations`** table, and skips files already listed.

```bash
php bin/migrate.php
```

### Existing database + old migration history (your dev machine)

If you previously ran the older numbered migrations and **pull this baseline layout**, clear bookkeeping **without deleting app data**, then stay aligned with fresh testers:

```bash
php bin/reset_migration_history_to_baseline.php --yes
php bin/migrate.php   # expect: no pending migrations
```

If you run **`migrate.php` first** after upgrading, you may get both old migration rows and **`001_baseline.sql`** recorded—run the reset script anytime to normalize (it wipes **only** the `migrations` table rows, then inserts the baseline row).

Historical incremental SQL is kept under **`migrations/archive/`** for reference only.

---

## License

MIT. See `LICENSE`.

---

## Notes

- **`.env`** is not committed; keep secrets there.
- **`vendor/`** is rebuilt with `composer install`.
- Remove or protect **`debug/`** helpers before production.
