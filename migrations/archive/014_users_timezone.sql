-- IANA time zone id from the browser (e.g. America/Los_Angeles); NULL = fall back to UTC.
ALTER TABLE users
    ADD COLUMN timezone VARCHAR(64) NULL DEFAULT NULL AFTER preferences_json;
