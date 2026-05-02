-- Compare emoji bytes exactly so UNIQUE/GROUP BY never merge distinct graphemes under unicode_ci rules.
ALTER TABLE thought_reactions
    MODIFY COLUMN emoji VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
