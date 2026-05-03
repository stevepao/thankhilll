# Data model and terms

Why this doc exists: a short glossary aligned with **`migrations/001_baseline.sql`** and **`includes/`** access helpers so UI, export, and MCP tools stay consistent.

## Note

- **What**: One gratitude entry per **`entry_date`** per author (**`uq_notes_user_entry_date`**). Fields include **`visibility`** (defaults **`private`** in schema), timestamps.
- **Owner**: **`notes.user_id`** (author).
- **UI**: **Today** composer for “today”, **Notes** list and **`note.php`** reader.
- **Export**: Full detail under **`notes.json`** for owned notes (`includes/user_export.php`).

## Thought

- **What**: Timestamped text on a note (**`note_thoughts`**); optional **`is_private`** (author-only visibility even when note is group-shared).
- **Owner**: Same as parent note author (thought belongs to **`notes.id`**).
- **UI**: Today composer timeline, note reader surfaces (**`includes/note_reading_thoughts.php`**, **`note_library_card.php`**).
- **Export**: Listed under each owned note in **`notes.json`**.

## Comment

- **What**: **`thought_comments`**, reply-style text tied to a **thought**.
- **Owner**: **`thought_comments.user_id`** (the writer).
- **UI**: Threads on shared notes where enabled (**`thought_comments.php`**).
- **Export**: On **your** notes: included under **`notes.json`** **`comments`** with author **`display_name`**. On **others’** notes: only **your** text appears in **`my_comments.json`** with **`note_author_name`** context.

## Reaction

- **What**: Emoji reaction on a thought (**`thought_reactions`**).
- **Owner**: **`thought_reactions.user_id`**.
- **UI**: Thought reactions UI (**`thought_reactions.php`**).
- **Export**: On owned notes in **`notes.json`** **`reactions`** (**`display_name`** + emoji).

## Photo / media

- **What**: **`note_media`** rows pointing at stored JPEG/PNG under **`NOTE_MEDIA_STORAGE_PATH`** / default **`storage/note_media`**.
- **Owner**: Implicitly the note author ( **`note_media.note_id` → `notes.user_id`** ).
- **UI**: **`media/note_photo.php`** (session + **`user_can_view_note`**).
- **Export**: Binary files under **`photos/`** plus **`photos`** array entries on owned notes.

## Group

- **What**: **`groups`** named circle with memberships (**`group_members`**).
- **Owner**: No single “owner” column in baseline summary; admins/memberships handled in group flows (**`group_helpers.php`**, **`group.php`**).
- **UI**: **Groups** pages.
- **Export**: **Group names** appear when listing **`shared_with_groups`** on **your** notes only.

## Membership

- **What**: **`group_members`** links **`user_id`** to **`group_id`**.
- **Owner**: N/A (relationship row).
- **UI**: Group roster management.
- **Export**: Not exported as standalone roster rows in personal ZIP.

## Join request

- **What**: **`group_invite_requests`** (user asks to join) distinct from **`group_invitations`** (invite-by-email style flows). Exact workflows live in group scripts.
- **Owner**: Requesting user + approving admin paths in code.
- **UI**: Group admin flows.
- **Export**: Not part of personal ZIP.

## Access shorthand

- **`user_can_view_note()`**: author **or** member of any **`note_groups`** group attached to the note.
- **`user_can_view_thought()`**: parent note visible **and** (thought not private **or** viewer is author).

## Related docs

- **`PRIVACY_AND_SHARING_MODEL.md`**, **`DATA_OWNERSHIP_AND_EXPORT.md`**, **`TIMEZONE.md`**
