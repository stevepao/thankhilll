SET NAMES utf8mb4;

ALTER TABLE user_data_exports
    MODIFY COLUMN status ENUM('queued', 'running', 'ready', 'failed', 'deleted_by_user') NOT NULL DEFAULT 'queued',
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER completed_at;
