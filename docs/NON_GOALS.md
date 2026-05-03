# Non-goals

Why this doc exists: scope creep is easier when everyone agrees what Thankhill is **not** trying to be.

- Not a **public social network** or open follower graph.
- Not optimized for **viral discovery**, trending feeds, or influencer-scale audiences.
- Not centered on **engagement metrics**, streak arm races, or loot-box style rewards (the product may show simple encouragement; it does not chase dopamine loops).
- Not an **AI journaling therapist** that reads private entries server-side for open-ended training on user gratitude text.
- Not a **general file locker**: uploads are **photos on notes**, validated as JPEG/PNG with caps (**`includes/note_media.php`**).
- Not a **collaborative wiki** or shared editing surface for arbitrary documents.
- Not guaranteeing **real-time** sync or offline-first conflict resolution beyond normal browser/PWA behavior.
- Not a replacement for **legal records** or clinical mental-health tooling.
- Not committing to **every host’s** preferred queue (Kafka, etc.): jobs use **HTTP cron** where needed (**`BACKGROUND_JOBS_AND_HOSTING.md`**).
- Not documenting secrets or tokens in git (use **`.env`**).

If a feature fights these boundaries, discuss before building.
