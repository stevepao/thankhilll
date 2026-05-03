# Security conventions

This document summarizes **security measures standardized in Thankhill** so operators and contributors know what to preserve when changing auth, media, cron, or forms. It is not a penetration-test report or threat model.

---

## Secrets and configuration

- **Environment variables** (`.env`, documented in **`.env.example`**) hold database credentials, OAuth client secrets, SMTP, Web Push keys, and operational secrets (**`CRON_SECRET`**, **`EXPORT_WORKER_TOKEN`**, **`MCP_MEDIA_SIGNING_KEY`**, session overrides, etc.). **`.env` is not committed.**
- Prefer **long random values** for shared secrets; compare untrusted input with **`hash_equals()`** so timing does not leak equality.

---

## Cross-site request forgery (CSRF)

- **Session-bound tokens** for mutating requests: **`includes/csrf.php`** generates an opaque token stored server-side; validation uses **`hash_equals()`**.
- **HTML forms** embed **`csrf_hidden_field()`**; handlers call **`csrf_verify_post_or_abort()`** before changing state.
- **JSON POSTs / AJAX** send **`csrf_token`** in the body or **`X-CSRF-Token`**; use **`csrf_verify_json_or_header_or_abort()`** or **`csrf_verify_decoded_json_or_header_or_abort()`** when the body was already parsed.

Independent of sign-in provider (Google, email OTP, etc.), CSRF is enforced at the handler boundary.

---

## Output encoding (XSS)

- **`includes/escape.php`** defines **`e()`**: **`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`** for dynamic text in HTML and quoted attributes.
- **Contract:** keep raw text in the database; escape **at output**, not by stripping HTML on ingest everywhere.

---

## SQL injection

- Use **PDO prepared statements** with bound parameters for user-derived values. Avoid string concatenation into SQL for untrusted input.

---

## Sessions and “stay signed in”

**`includes/session.php`** and **`auth.php`** implement:

| Measure | Purpose |
|--------|---------|
| **`session.use_strict_mode`** / **`session.use_only_cookies`** | Reduce fixation and session ID leakage via URLs. |
| **HttpOnly session cookie** | Keeps session ID out of `document.cookie`. |
| **SameSite=Lax** | Reduces cross-site cookie submission on common navigations. |
| **Secure cookie** when HTTPS (or forced via **`SESSION_COOKIE_SECURE`**) | Sends cookie only over TLS when enabled. |
| **`session_regenerate_id(true)` on login** (**`session_commit_login()`**) | Mitigates fixation after credential proof. |
| **Idle timeout** (**`SESSION_IDLE_TIMEOUT_SECONDS`**) | Limits exposure of abandoned sessions. |
| **User-Agent binding** (**`hash_equals`** on stored vs current UA) | Cheap hint that the session is still the same browser instance (not a cryptographic guarantee). |

**Refresh tokens** (**`includes/auth_refresh_token.php`**): optional bounded “stay signed in” via an **HttpOnly** cookie (`thankhill_refresh`) and **SHA-256 hashes** stored in **`auth_refresh_tokens`**—not **`localStorage`** for authentication. Absolute expiry is capped (**`AUTH_REFRESH_TOKEN_LIFETIME_SECONDS`**).

---

## Login redirects (open redirect)

- **`includes/auth_redirect.php`** **`auth_redirect_uri_safe()`** allows only **same-origin path/query** targets (rejects schemes and protocol-relative URLs). Used after login **`next=`** handling.

---

## Email one-time codes

- OTP material is stored with **`password_hash()`** (see **`auth/email/request_code.php`**); verification uses **`password_verify()`**.
- **Rate limiting / resend throttle** is enforced in **`includes/email_otp_repository.php`** using UTC-aware timestamps (see **`docs/TIMEZONE.md`**).

---

## HTTP cron and background workers

Untrusted callers must not trigger privileged jobs:

| Endpoint / script | Gate |
|-------------------|------|
| **`cron_daily_gratitude_reminders.php`** | Query parameter **`token`** must match **`CRON_SECRET`** (**`hash_equals`**). |
| **`export_worker.php`** | Query **`token`** validated by **`user_export_worker_token_valid()`** against **`EXPORT_WORKER_TOKEN`** (**`includes/user_export.php`**). |
| **`bin/send_daily_gratitude_reminders.php`** | CLI-oriented entry (see script header); operators choose CLI vs HTTP cron per host. |

Responses use plain text; failures return **403** / **500** as appropriate.

---

## Photos and media

Two paths, both avoid serving files by predictable URL alone:

1. **Browser UI** — **`media/note_photo.php`** requires a normal **logged-in session**, loads the **`note_media`** row, then **`user_can_view_note()`** (**`includes/note_access.php`**). Denied access returns **404** (no existence oracle). Responses set **`X-Content-Type-Options: nosniff`** and **`Cache-Control: private`** with a short max-age.
2. **MCP / agents** — **`mcp/photo.php`** accepts **HMAC-signed GET URLs** built by **`mcp/media_signing.php`** using **`MCP_MEDIA_SIGNING_KEY`**. Signature covers user id, photo id, variant, and expiry; **`hash_equals`** verifies; expiry is capped (~15 minutes). Only the **note owner’s** id participates in signing for that row.

On disk, **`includes/note_media.php`** resolves paths under **`NOTE_MEDIA_STORAGE_ROOT`** with **`realpath`** containment so **`..`** cannot escape the storage root.

**Upload validation:** MIME sniffing, **`getimagesize`**, JPEG/PNG allowlist, dimension caps, and max bytes—server-side enforcement mirrors client expectations.

---

## MCP JSON-RPC API

- **`mcp/v1.php`**: authenticated via **Bearer** tokens resolved by **`mcp_access_token_resolve_user_id()`** (**`includes/mcp_access_token.php`**). Tokens are **hashed at rest**; plaintext shown once at issuance; expired/revoked tokens are rejected.
- Internal issuance/revoke routes under **`internal/mcp/`** use **session login + CSRF** where applicable (see **`internal/README.md`**).

---

## Sensitive downloads and indexing

- **`me_export_download.php`** and **`export_worker.php`** send **`X-Robots-Tag: noindex, nofollow`** where relevant; export downloads use **`Cache-Control: private, no-store`** (see **`me_export_download.php`**).

---

## Account deletion

- **`account_delete.php`** implements permanent deletion behind **session auth**, **CSRF**, and explicit confirmation steps (see in-app flow). Treat changes here as high-risk.

---

## Operational hygiene

- Restrict or remove **`debug/`** helpers in production (see root **`README.md`**).
- Production **`Authorization`** header forwarding for OIDC is documented under URL rewrites (**`.htaccess`** / **`README.md`**).

---

## Related documentation

- **`docs/TIMEZONE.md`** — UTC storage and safe parsing (also affects security-sensitive comparisons like OTP throttle).
- **`internal/README.md`** — MCP token HTTP endpoints and CSRF expectations.

When adding a new privileged HTTP entrypoint (cron, webhook, export, media), default to: **shared secret or session auth**, **constant-time secret compare**, **minimal response leakage**, and **CSRF** for browser-initiated mutations.
