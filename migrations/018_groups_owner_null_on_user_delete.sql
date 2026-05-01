-- Allow groups to survive when the owning user deletes their account (no auto-reassignment).
ALTER TABLE `groups`
    MODIFY owner_user_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `groups` DROP FOREIGN KEY fk_groups_owner_user;

ALTER TABLE `groups`
    ADD CONSTRAINT fk_groups_owner_user
        FOREIGN KEY (owner_user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT;
