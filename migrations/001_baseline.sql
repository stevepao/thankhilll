-- Thankhill schema baseline (collapsed from pre-alpha migrations).
-- Fresh installs: run `php bin/migrate.php` (applies this file once).
-- Existing databases already at this shape: run `php bin/reset_migration_history_to_baseline.php`
-- after verifying schema matches (does not run DDL).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    login_email_normalized VARCHAR(255) NULL DEFAULT NULL,
    preferences_json JSON NULL,
    timezone VARCHAR(64) NULL DEFAULT NULL,
    daily_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_reminder_sent_at DATE NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_login_email_normalized (login_email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_identities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    oauth_contact_email_normalized VARCHAR(255) NULL DEFAULT NULL,
    secret_hash TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    CONSTRAINT fk_auth_identities_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT uq_auth_identities_provider_identifier
        UNIQUE (provider, identifier),
    INDEX idx_auth_identities_user_id (user_id),
    INDEX idx_auth_identities_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    owner_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groups_owner_user
        FOREIGN KEY (owner_user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT,
    INDEX idx_groups_owner_user_id (owner_user_id),
    INDEX idx_groups_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_members_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_group_members_group
        FOREIGN KEY (group_id) REFERENCES `groups`(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT uq_group_members_user_group
        UNIQUE (user_id, group_id),
    INDEX idx_group_members_group_id (group_id),
    INDEX idx_group_members_user_id (user_id),
    INDEX idx_group_members_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    invited_user_id INT UNSIGNED NULL DEFAULT NULL,
    token VARCHAR(255) NOT NULL,
    invited_by_user_id INT UNSIGNED NOT NULL,
    accepted_at DATETIME NULL,
    declined_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_group_invitations_group
        FOREIGN KEY (group_id) REFERENCES `groups`(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_group_invitations_invited_by_user
        FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT fk_group_invitations_invited_user
        FOREIGN KEY (invited_user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT,
    CONSTRAINT uq_group_invitations_token
        UNIQUE (token),
    INDEX idx_group_invitations_group_id (group_id),
    INDEX idx_group_invitations_email (email),
    INDEX idx_group_invitations_created_at (created_at),
    INDEX idx_group_invitations_expires_pending (expires_at, accepted_at),
    INDEX idx_group_invitations_invited_user_pending (invited_user_id, accepted_at, declined_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_invite_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    requester_user_id INT UNSIGNED NOT NULL,
    requested_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL DEFAULT NULL,
    declined_at DATETIME NULL DEFAULT NULL,
    CONSTRAINT fk_group_invite_requests_group
        FOREIGN KEY (group_id) REFERENCES `groups`(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_group_invite_requests_requester
        FOREIGN KEY (requester_user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_group_invite_requests_group_pending (group_id, approved_at, declined_at),
    INDEX idx_group_invite_requests_requester (requester_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    entry_date DATE NOT NULL,
    visibility VARCHAR(40) NOT NULL DEFAULT 'private',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT,
    UNIQUE KEY uq_notes_user_entry_date (user_id, entry_date),
    INDEX idx_notes_user_id (user_id),
    INDEX idx_notes_visibility_created_at (visibility, created_at),
    INDEX idx_notes_entry_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_groups (
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

CREATE TABLE IF NOT EXISTS reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reactions_note
        FOREIGN KEY (note_id) REFERENCES notes(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_reactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT uq_reactions_note_user_emoji
        UNIQUE (note_id, user_id, emoji),
    INDEX idx_reactions_user_id (user_id),
    INDEX idx_reactions_note_id (note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(512) NOT NULL,
    platform VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    CONSTRAINT fk_notification_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_notification_tokens_user_id (user_id),
    INDEX idx_notification_tokens_platform (platform),
    INDEX idx_notification_tokens_last_used_at (last_used_at),
    UNIQUE KEY uq_notification_tokens_token (token(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_login_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(320) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_login_otps_email (email),
    INDEX idx_email_login_otps_expires_at (expires_at),
    INDEX idx_email_login_otps_consumed_at (consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS note_thoughts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_note_thoughts_note
        FOREIGN KEY (note_id) REFERENCES notes(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    INDEX idx_note_thoughts_note_created (note_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS thought_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thought_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
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
