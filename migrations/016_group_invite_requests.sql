-- Member-initiated invite requests; admins approve to create real invitations (separate rows).
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
