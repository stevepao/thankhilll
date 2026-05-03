# Security audit (pragmatic)

Why this doc exists: **`SECURITY.md`** lists conventions. This doc adds **operator-focused lessons** and MCP gateway edge cases seen in **`mcp/v1.php`** without pretending to be a formal pentest.

## Authentication model (high level)

- **Browser users**: PHP session after Google OIDC or email OTP (**`auth/`**, **`session_commit_login()`**). Idle timeout and UA binding (**`includes/session.php`**). Optional **HttpOnly refresh cookie** with hashed DB rows (**`includes/auth_refresh_token.php`**).
- **MCP callers**: **Bearer** tokens mapped through **`mcp_access_token_resolve_user_id()`**; plaintext shown once at issuance (**`includes/mcp_access_token.php`**).

## Token handling principles

- Put secrets in **`.env`**, not committed configs. Examples: **`EXPORT_WORKER_TOKEN`**, **`CRON_SECRET`**, **`MCP_MEDIA_SIGNING_KEY`**, OAuth client secrets, DB passwords.
- Compare secrets with **`hash_equals()`** where strings come from HTTP or cookies.
- Store **hashes** for refresh tokens and MCP tokens at rest; never log plaintext bearer or OTP codes.

## MCP gateway hardening (lessons from `mcp/v1.php`)

- **`Authorization` visibility**: some stacks strip **`HTTP_AUTHORIZATION`**. Code checks **`HTTP_AUTHORIZATION`**, **`REDIRECT_HTTP_AUTHORIZATION`**, getenv fallbacks, and **`apache_request_headers()`** as fallback (**`th_mcp_authorization_header_raw()`**). Configure **`Authorization`** passthrough for OIDC-style setups (**README.md** `.htaccess` note).
- **Methods**: **GET** returns small JSON health; **POST** only for JSON-RPC; others **405**.
- **`Content-Type`**: **`application/json`** checked with a regex anchor (**`^application/json\b`**). Wrong type → **415**.
- **JSON parsing**: **`JSON_THROW_ON_ERROR`** on decode; invalid bodies → JSON-RPC parse errors.
- **Encoding responses**: **`TH_MCP_JSON_ENCODE`** uses **`JSON_THROW_ON_ERROR`** and **`JSON_INVALID_UTF8_SUBSTITUTE`** so tool output with messy Unicode does not blank the RPC envelope.
- **Timezone**: gateway sets **`date_default_timezone_set('UTC')`** at bootstrap for predictable logs and timestamps on that entrypoint.
- **PDO bootstrap**: MCP loads DB via **`th_mcp_pdo_from_env()`** without the **`db.php`** **`SET SESSION time_zone = '+00:00'`** hook. Token expiry SQL uses **`NOW()`**. If you extend MCP DB usage for app **`DATETIME`** semantics, consider aligning session TZ or using UTC helpers consciously (**`docs/TIMEZONE.md`**).

## Media URLs

- **Browser**: **`media/note_photo.php`** requires login + **`user_can_view_note()`**; deny looks like **404**.
- **MCP**: **`mcp/photo.php`** serves via **HMAC** query params (**`mcp/media_signing.php`**). **`MCP_MEDIA_SIGNING_KEY`** required; expiry capped (~15 minutes); **`hash_equals`** for signature verify.

## Export security

- **Download**: **`me_export_download.php`** requires **`require_login()`**, **`status = ready`**, matching **`user_id`**, resolved path under storage root.
- **Email**: points to **Me** only (**`app_absolute_url('/me.php')`**), not signed ZIP URLs.
- **Storage**: **`EXPORT_STORAGE_PATH`** or default **`storage/exports`**; ZIP finalized atomically with temp rename pattern in **`user_export_build_and_finalize()`**.
- **Retention / user delete**: user can remove ready exports from disk while keeping audit row (**`deleted_by_user`**).

## Logging guidance

- Avoid **`error_log()`** of Bearer headers, CSRF tokens, **`EXPORT_WORKER_TOKEN`** values, OTP plaintext, or full **`note`** bodies pulled for debugging.
- When debugging MCP, log **method names** or opaque ids, not RPC **`params`** payloads containing user text.

## Related docs

- **`SECURITY.md`**, **`internal/README.md`**, **`DATA_OWNERSHIP_AND_EXPORT.md`**
