-- Groups created by users.
CREATE TABLE IF NOT EXISTS `groups` (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groups_created_by_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    INDEX idx_groups_created_by_user_id (created_by_user_id),
    INDEX idx_groups_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Memberships link users to groups with a role.
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

-- Invitations are emailed and redeemed by a token.
CREATE TABLE IF NOT EXISTS group_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    invited_by_user_id INT UNSIGNED NOT NULL,
    accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_invitations_group
        FOREIGN KEY (group_id) REFERENCES `groups`(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_group_invitations_invited_by_user
        FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT uq_group_invitations_token
        UNIQUE (token),
    INDEX idx_group_invitations_group_id (group_id),
    INDEX idx_group_invitations_email (email),
    INDEX idx_group_invitations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
