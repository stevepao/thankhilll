-- Stores email OTP challenges for cross-device sign-in (hash only; plaintext exists only in outbound mail).
-- Hygiene (optional cron): DELETE FROM email_login_otps WHERE expires_at < NOW() - INTERVAL 1 DAY;

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
