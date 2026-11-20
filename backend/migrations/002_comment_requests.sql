-- Additive migration: preserve existing discussions, task edits and seed dates.
ALTER TABLE comments ADD COLUMN help_requested INTEGER NOT NULL DEFAULT 0 CHECK(help_requested IN (0,1));
ALTER TABLE comments ADD COLUMN handled_at TEXT;
ALTER TABLE comments ADD COLUMN handled_by INTEGER REFERENCES users(id);
ALTER TABLE comments ADD COLUMN attention_version INTEGER NOT NULL DEFAULT 0;
