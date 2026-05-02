-- MCP bearer tokens (hashed at rest). Plaintext shown once at creation only.

CREATE TABLE IF NOT EXISTS mcp_access_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of opaque raw bytes',
    label VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    CONSTRAINT fk_mcp_access_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    UNIQUE KEY uq_mcp_access_tokens_hash (token_hash),
    KEY idx_mcp_access_tokens_user_id (user_id),
    KEY idx_mcp_access_tokens_expires (expires_at),
    KEY idx_mcp_access_tokens_user_active (user_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
