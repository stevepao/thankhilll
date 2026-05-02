# Changelog

All notable changes to **Thankhill** are documented here.

Version numbers follow **semantic versioning** (`MAJOR.MINOR.PATCH`) where practical during alpha.

---

## [0.5.0](https://github.com/stevepao/thankhilll/releases/tag/v0.5.0) — 2026-05-01

First packaged **alpha** release (mobile-first gratitude journal).

### Added

- **Today** — One gratitude note per calendar day (user timezone); timestamped **thoughts**; optional **photos**; optional sharing with **groups**; configurable visibility for shared Today feed.
- **Notes** — Library view with filters; **your notes** vs **everything visible** (including group-shared entries).
- **Note detail** — Read-only note view with **emoji reactions** and **comments** on shared, non-private thoughts.
- **Groups** — Create groups, memberships, **email invitations** with expiry, **invite requests**, leave/delete flows.
- **Me** — Display name, **preferences** (defaults for visibility and Notes scope), **timezone**, **notification** toggles, sign-out, **account deletion** with Google token revocation when stored.
- **Authentication** — **Sign in with Google** (OpenID Connect); **email OTP** sign-in via SMTP; server-side sessions with idle timeout and **bounded refresh-token** reauthentication (HttpOnly cookie + `auth_refresh_tokens`).
- **Web Push** — Subscription storage and per-user notification preferences (delivery depends on server/VAPID configuration).
- **Legal** — Public **Privacy Policy** (`/policy`) and **Terms of Use** (`/terms`); links from sign-in.
- **Documentation** — `README.md` oriented toward testers and operators.

### Changed

- **Database migrations** — Historical incremental migrations collapsed into **`migrations/001_baseline.sql`** for fresh installs; prior SQL retained under **`migrations/archive/`** for reference only.
- **`bin/reset_migration_history_to_baseline.php`** — Clears `migrations` bookkeeping only (no app data loss) so existing dev databases align with the baseline workflow after upgrading.

### For operators / testers

- **Fresh database:** `php bin/migrate.php` (applies `001_baseline.sql`).
- **Existing DB after pulling this layout:** `php bin/reset_migration_history_to_baseline.php --yes` then `php bin/migrate.php`.
- Configure **`.env`** per `.env.example` (database, Google OIDC, SMTP, optional VAPID/cron).
- Remove or lock down **`debug/`** on production hosts.
