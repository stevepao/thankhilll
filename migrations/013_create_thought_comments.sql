-- Quiet affirmations on shared, non-private thoughts (flat, no threads).
CREATE TABLE IF NOT EXISTS thought_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thought_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body VARCHAR(280) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_thought_comments_thought
        FOREIGN KEY (thought_id) REFERENCES note_thoughts(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_thought_comments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_thought_comments_thought (thought_id),
    INDEX idx_thought_comments_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
