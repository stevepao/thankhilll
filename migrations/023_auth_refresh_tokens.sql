-- HttpOnly refresh cookies for silent session renewal after idle expiry (bounded lifetime).

CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of opaque token bytes',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_refresh_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    UNIQUE KEY uq_auth_refresh_tokens_hash (token_hash),
    KEY idx_auth_refresh_tokens_user_id (user_id),
    KEY idx_auth_refresh_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
