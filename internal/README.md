# Internal HTTP endpoints

These routes are **not** linked from the product UI. They exist for operators,
integrations, and tooling.

The **public MCP endpoint** is **`POST /mcp/v1`** only, served by **`public/mcp-v1.php`** (single file, no app includes). It requires **HTTPS**, **`Authorization: Bearer`** with user-issued MCP tokens from below—not browser sessions—and **`Content-Type: application/json`**. It does **not** implement SSE, **`GET`**, or **`Accept`** checks. Supported JSON-RPC methods: **`initialize`**, **`notifications/initialized`** (notification, **204**), **`tools/list`** (empty **`tools`**); anything else returns **`-32601`**. Invalid or missing bearer credentials yield **401** with **`WWW-Authenticate: Bearer`**.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` / `POST` | `/internal/mcp/token/issue` | **Browser UI** to issue a token once (masked field, copy, [Save in 1Password](https://developer.1password.com/docs/web/add-1password-button-website/) including gateway URL `APP_BASE_URL` + `/mcp/v1` and contact email); **POST** uses form CSRF. Not linked from product navigation. |
| `POST` | `/internal/mcp/token/create` | Issue an MCP bearer token for the signed-in user (**CSRF** required). JSON/API-friendly. |
| `GET` | `/internal/mcp/tokens` | List that user’s tokens (`id`, `created_at`, `expires_at`, `revoked_at`, `label`, `description`; never returns secret). |
| `POST` | `/internal/mcp/token/revoke` | Revoke one token (**CSRF** required): send **`token_id`** *or* **`token_hash`** (64-char hex stored hash), not both. Soft-revokes (`revoked_at`); row kept. |

Configure your web server to map these paths to the matching **`internal/mcp/**/*.php`** scripts if you do not use the root **`.htaccess`** rewrites (including **`internal/mcp/token/issue.php`** for **`/internal/mcp/token/issue`**).

**`public/mcp-v1.php`** validates bearer tokens against **`mcp_access_tokens`** with the same rules as **`mcp_access_token_resolve_user_id()`** in **`includes/mcp_access_token.php`** (returns `null` for expired, revoked, or unknown tokens—revocation takes effect on the next lookup). Other integrations can call that helper in PHP when they need to resolve a token outside this endpoint.

### Calling `POST /internal/mcp/token/create`

1. Sign in with a normal browser session (session cookie).
2. Send **CSRF** the same way as other JSON POSTs: JSON body field **`csrf_token`** or header **`X-CSRF-Token`** (see `includes/csrf.php`). On most authenticated app pages the footer embeds the current token in the JSON script `#thankhill-push-device-bootstrap` under the **`csrf`** key.
3. Optional JSON body: **`label`** (string, ≤255 chars) to annotate the token in the database.

Example:

```bash
curl -sS -X POST 'https://your-domain.example/internal/mcp/token/create' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: PHPSESSID=...' \
  -H 'X-CSRF-Token: YOUR_CSRF_FROM_SESSION' \
  -d '{"label":"Cursor MCP"}'
```

### Calling `GET /internal/mcp/tokens`

Same session cookie as the logged-in user; no CSRF.

```bash
curl -sS 'https://your-domain.example/internal/mcp/tokens' \
  -H 'Cookie: PHPSESSID=...'
```

### Calling `POST /internal/mcp/token/revoke`

JSON body must include exactly one of **`token_id`** (integer from the list endpoint) or **`token_hash`** (64-char lowercase hex of the stored credential hash—never the raw bearer secret). Include CSRF in the JSON body as **`csrf_token`** or send header **`X-CSRF-Token`**.

```bash
curl -sS -X POST 'https://your-domain.example/internal/mcp/token/revoke' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: PHPSESSID=...' \
  -H 'X-CSRF-Token: YOUR_CSRF_FROM_SESSION' \
  -d '{"token_id":42}'
```
