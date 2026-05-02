# Internal HTTP endpoints

These routes are **not** linked from the product UI. They exist for operators,
integrations, and tooling.

| Method | Path | Purpose |
|--------|------|---------|
| `GET` / `POST` | `/internal/mcp/token/issue` | **Browser UI** to issue a token once (masked field, copy, [Save in 1Password](https://developer.1password.com/docs/web/add-1password-button-website/)); **POST** uses form CSRF. Not linked from product navigation. |
| `POST` | `/internal/mcp/token/create` | Issue an MCP bearer token for the signed-in user (**CSRF** required). JSON/API-friendly. |
| `GET` | `/internal/mcp/tokens` | List that user’s tokens (`id`, timestamps, `label`; never returns secret). |
| `POST` | `/internal/mcp/token/revoke` | Revoke one token by **`token_id`** (**CSRF** required). |

Configure your web server to map these paths to the matching **`internal/mcp/**/*.php`** scripts if you do not use the root **`.htaccess`** rewrites (including **`internal/mcp/token/issue.php`** for **`/internal/mcp/token/issue`**).

MCP gateways should authenticate requests with **`mcp_access_token_resolve_user_id()`** in **`includes/mcp_access_token.php`** (returns `null` for expired, revoked, or unknown tokens).

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

JSON body must include **`token_id`** (integer row id from the list endpoint) plus CSRF (body or header).

```bash
curl -sS -X POST 'https://your-domain.example/internal/mcp/token/revoke' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: PHPSESSID=...' \
  -H 'X-CSRF-Token: YOUR_CSRF_FROM_SESSION' \
  -d '{"token_id":42}'
```
