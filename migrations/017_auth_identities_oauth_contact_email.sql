-- OAuth providers may supply an account email even when users.login_email_normalized is unset.
ALTER TABLE auth_identities
    ADD COLUMN oauth_contact_email_normalized VARCHAR(255) NULL DEFAULT NULL
        AFTER identifier;

-- Backfill Google rows from existing login emails where present.
UPDATE auth_identities ai
INNER JOIN users u ON u.id = ai.user_id
SET ai.oauth_contact_email_normalized = u.login_email_normalized
WHERE ai.provider = 'google'
  AND u.login_email_normalized IS NOT NULL
  AND TRIM(u.login_email_normalized) <> '';
