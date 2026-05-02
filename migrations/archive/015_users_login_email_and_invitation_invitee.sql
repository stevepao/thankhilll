-- Match invitations to accounts by normalized email (email OTP + Google profile email on login).
ALTER TABLE users
    ADD COLUMN login_email_normalized VARCHAR(255) NULL DEFAULT NULL AFTER display_name,
    ADD INDEX idx_users_login_email_normalized (login_email_normalized);

UPDATE users u
INNER JOIN auth_identities ai ON ai.user_id = u.id AND ai.provider = 'email'
SET u.login_email_normalized = LOWER(TRIM(ai.identifier))
WHERE u.login_email_normalized IS NULL;

-- Invitee linkage + decline (UI first-class for existing users).
ALTER TABLE group_invitations
    ADD COLUMN invited_user_id INT UNSIGNED NULL DEFAULT NULL AFTER email,
    ADD COLUMN declined_at DATETIME NULL DEFAULT NULL,
    ADD INDEX idx_group_invitations_invited_user_pending (invited_user_id, accepted_at, declined_at),
    ADD CONSTRAINT fk_group_invitations_invited_user
        FOREIGN KEY (invited_user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE RESTRICT;

UPDATE group_invitations gi
INNER JOIN users u ON u.login_email_normalized IS NOT NULL AND u.login_email_normalized = gi.email
SET gi.invited_user_id = u.id
WHERE gi.invited_user_id IS NULL
  AND gi.accepted_at IS NULL
  AND gi.declined_at IS NULL;
