-- Web Push subscriptions (per browser/device) and per-user push preference toggles.

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of endpoint URL; unique index safe for long URLs',
    endpoint_url TEXT NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    expiration_time_ms BIGINT UNSIGNED NULL COMMENT 'PushSubscription.expirationTime (ms); optional',
    user_agent VARCHAR(512) NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_push_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    UNIQUE KEY uq_push_subscriptions_endpoint_hash (endpoint_hash),
    INDEX idx_push_subscriptions_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    push_reminders_enabled TINYINT(1) NOT NULL DEFAULT 0,
    push_comment_replies_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_notification_prefs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
