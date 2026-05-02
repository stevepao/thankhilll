-- Author-only private thoughts within a shared daily note (no per-thought groups).

ALTER TABLE note_thoughts
    ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
