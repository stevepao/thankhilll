-- Daily gratitude evening reminders: column flags + dedupe by user's local calendar day.

ALTER TABLE users
    ADD COLUMN daily_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone,
    ADD COLUMN last_reminder_sent_at DATE NULL DEFAULT NULL AFTER daily_reminder_enabled;

-- Seed opt-in from existing notification prefs (Me tab → reminders) where already enabled.
UPDATE users u
INNER JOIN user_notification_prefs p ON p.user_id = u.id AND p.push_reminders_enabled = 1
SET u.daily_reminder_enabled = 1;
