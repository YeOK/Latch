-- Phase 4: user locale preference (i18n)
PRAGMA foreign_keys = ON;

ALTER TABLE users ADD COLUMN locale TEXT;