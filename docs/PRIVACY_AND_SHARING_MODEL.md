# Privacy and sharing model

Why this doc exists: the database rules (`includes/note_access.php`, `note_groups`, **`visibility`**) should match plain-language expectations for users and anyone changing composer or export behavior.

## What “Just for me” means

- A note can stay **private** at the note level (`notes.visibility` and **`note_groups`** rows drive who sees it).
- **Thoughts** can be marked **private** (`note_thoughts.is_private`): even if the note is shared in groups, a **private** thought is visible only to the **note author** (`user_can_view_thought()`).
- **Does not mean**: encrypted-at-rest on the server by default, anonymity on the public internet, or protection from your hosting provider or legal process. It means **other Thankhill users** only see what sharing rules allow.

## How group sharing works (conceptual)

- **Groups** are small membership lists. An **admin** creates the group and can invite or approve requests (see **`groups.php`** and related scripts).
- **Sharing a note** attaches it to chosen groups (`note_groups`). Any **member** of those groups who could already see the note surface can read it according to **`user_can_view_note()`** (author **or** member of a linked group).
- **Comments / reactions** on shared thoughts follow product rules in **`thought_comments.php`** / **`thought_reactions.php`** (group visibility on the parent note, etc.). Details belong in code review when you change those modules.

## What gets exported vs what never leaves “your archive” philosophy

- **Exported**: your authored notes and related activity as documented in **`DATA_OWNERSHIP_AND_EXPORT.md`**, plus **`my_comments.json`** for comments you wrote elsewhere.
- **Not exported as bulk copies** other authors’ note bodies or their photo binaries when you only commented on their entries.
- **Never promised in export**: emails, internal numeric IDs, server logs, or other operators’ database internals.

## What never leaves the system (operator-facing)

- Secrets stay in **`.env`** (not shipped in ZIP).
- Auth artifacts (session ids, refresh token opaque values, MCP plaintext bearer shown once at issuance) are not part of the user ZIP format.

## Privacy design principles (match product intent)

1. **Small circles**: groups support trusted circles, not a global feed.
2. **Author control**: default flows emphasize **your** journal and explicit choices about sharing.
3. **Minimal disclosure in archives**: export favors **display names**, ISO timestamps, and explicit omission of emails and internal ids (`user_export_readme_text()`).
4. **Separate “what I wrote on others’ entries”**: **`my_comments.json`** preserves **your** words without ingesting their full entries.
5. **Defense in depth in the app**: CSRF, session rules, signed MCP media URLs, and authenticated export download are documented in **`SECURITY.md`** and **`SECURITY_AUDIT.md`**.

## Related docs

- **`DATA_OWNERSHIP_AND_EXPORT.md`**, **`DATA_MODEL_AND_TERMS.md`**, **`docs/SECURITY.md`**
