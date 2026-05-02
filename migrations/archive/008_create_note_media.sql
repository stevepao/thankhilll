-- Photos attached to gratitude notes (files live outside web root; paths are relative keys).
CREATE TABLE IF NOT EXISTS note_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_note_media_note
        FOREIGN KEY (note_id) REFERENCES notes(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_note_media_note_id (note_id),
    INDEX idx_note_media_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
