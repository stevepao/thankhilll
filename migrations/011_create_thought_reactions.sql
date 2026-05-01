-- Lightweight emoji reactions for note thoughts.
CREATE TABLE IF NOT EXISTS thought_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thought_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_thought_reactions_thought
        FOREIGN KEY (thought_id) REFERENCES note_thoughts(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_thought_reactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT uq_thought_reactions_thought_user_emoji
        UNIQUE (thought_id, user_id, emoji),
    INDEX idx_thought_reactions_thought (thought_id),
    INDEX idx_thought_reactions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
