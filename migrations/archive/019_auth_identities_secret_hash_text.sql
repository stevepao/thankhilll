-- Google OAuth refresh tokens exceed VARCHAR(255); email OTP hashes stay short.
ALTER TABLE auth_identities
    MODIFY secret_hash TEXT NULL DEFAULT NULL;
