-- Extend existing notes table with author user and visibility.
ALTER TABLE notes
    ADD COLUMN user_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN visibility VARCHAR(40) NOT NULL DEFAULT 'private' AFTER content,
    ADD INDEX idx_notes_user_id (user_id),
    ADD INDEX idx_notes_visibility_created_at (visibility, created_at),
    ADD CONSTRAINT fk_notes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT;

-- Join table for notes visible to specific groups.
CREATE TABLE IF NOT EXISTS note_group_visibility (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_note_group_visibility_note
        FOREIGN KEY (note_id) REFERENCES notes(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_note_group_visibility_group
        FOREIGN KEY (group_id) REFERENCES `groups`(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT uq_note_group_visibility_note_group
        UNIQUE (note_id, group_id),
    INDEX idx_note_group_visibility_group_id (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
