-- Email invitations: fixed expiry window (default 7 days from creation).
ALTER TABLE group_invitations ADD COLUMN expires_at DATETIME NULL;
UPDATE group_invitations
SET expires_at = DATE_ADD(IFNULL(created_at, NOW()), INTERVAL 7 DAY)
WHERE expires_at IS NULL;
ALTER TABLE group_invitations MODIFY expires_at DATETIME NOT NULL;

CREATE INDEX idx_group_invitations_expires_pending
    ON group_invitations (expires_at, accepted_at);

-- Junction table name aligned with product (`note_groups`).
RENAME TABLE note_group_visibility TO note_groups;

-- Owner column naming (`owner_user_id`).
ALTER TABLE `groups` DROP FOREIGN KEY fk_groups_created_by_user;
ALTER TABLE `groups`
    CHANGE COLUMN created_by_user_id owner_user_id INT UNSIGNED NOT NULL;
ALTER TABLE `groups` ADD CONSTRAINT fk_groups_owner_user
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT;
