-- Latch schema v2 — theme preferences (Phase 2)
PRAGMA foreign_keys = ON;

ALTER TABLE users ADD COLUMN theme_mode TEXT NOT NULL DEFAULT 'system'
    CHECK (theme_mode IN ('light', 'dark', 'system'));