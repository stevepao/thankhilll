SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_data_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('queued', 'running', 'ready', 'failed') NOT NULL DEFAULT 'queued',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL DEFAULT NULL,
    completed_at DATETIME NULL DEFAULT NULL,
    file_path VARCHAR(512) NULL DEFAULT NULL COMMENT 'Relative path under export storage root',
    file_size BIGINT UNSIGNED NULL DEFAULT NULL,
    error_message TEXT NULL DEFAULT NULL,
    CONSTRAINT fk_user_data_exports_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_user_data_exports_user_requested (user_id, requested_at DESC),
    INDEX idx_user_data_exports_status_requested (status, requested_at ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
