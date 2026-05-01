-- One gratitude note per user per calendar day; thoughts as timestamped rows.

CREATE TABLE IF NOT EXISTS note_thoughts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_note_thoughts_note
        FOREIGN KEY (note_id) REFERENCES notes(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_note_thoughts_note_created (note_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notes
    ADD COLUMN entry_date DATE NULL AFTER user_id,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE notes SET entry_date = DATE(created_at) WHERE entry_date IS NULL;

INSERT INTO note_thoughts (note_id, body, created_at)
SELECT id,
       CASE WHEN CHAR_LENGTH(TRIM(content)) > 0 THEN TRIM(content) ELSE ' ' END,
       created_at
FROM notes;

CREATE TEMPORARY TABLE tmp_note_merge_map (
    lose_id INT UNSIGNED NOT NULL PRIMARY KEY,
    keep_id INT UNSIGNED NOT NULL,
    INDEX idx_merge_keep (keep_id)
);

INSERT INTO tmp_note_merge_map (lose_id, keep_id)
SELECT n.id,
       (
           SELECT MIN(n2.id)
           FROM notes n2
           WHERE n2.user_id <=> n.user_id
             AND n2.entry_date <=> n.entry_date
       ) AS keep_id
FROM notes n
WHERE n.user_id IS NOT NULL
  AND n.entry_date IS NOT NULL
  AND n.id > (
      SELECT MIN(n3.id)
      FROM notes n3
      WHERE n3.user_id <=> n.user_id
        AND n3.entry_date <=> n.entry_date
  );

DELETE loser FROM reactions loser
INNER JOIN tmp_note_merge_map m ON loser.note_id = m.lose_id
INNER JOIN reactions keeper
    ON keeper.note_id = m.keep_id
    AND keeper.user_id = loser.user_id
    AND keeper.emoji = loser.emoji;

UPDATE reactions r
INNER JOIN tmp_note_merge_map m ON r.note_id = m.lose_id
SET r.note_id = m.keep_id;

UPDATE note_thoughts t
INNER JOIN tmp_note_merge_map m ON t.note_id = m.lose_id
SET t.note_id = m.keep_id;

UPDATE note_media med
INNER JOIN tmp_note_merge_map m ON med.note_id = m.lose_id
SET med.note_id = m.keep_id;

INSERT IGNORE INTO note_groups (note_id, group_id)
SELECT m.keep_id, ng.group_id
FROM note_groups ng
INNER JOIN tmp_note_merge_map m ON ng.note_id = m.lose_id;

DELETE FROM notes WHERE id IN (SELECT lose_id FROM tmp_note_merge_map);

DROP TEMPORARY TABLE tmp_note_merge_map;

ALTER TABLE notes DROP COLUMN content;

ALTER TABLE notes MODIFY COLUMN entry_date DATE NOT NULL;

CREATE UNIQUE INDEX uq_notes_user_entry_date ON notes (user_id, entry_date);

CREATE INDEX idx_notes_entry_date ON notes (entry_date);
