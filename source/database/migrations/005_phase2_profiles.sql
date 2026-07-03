-- Latch schema — profiles & avatars (Phase 2)
PRAGMA foreign_keys = ON;

ALTER TABLE users ADD COLUMN avatar_url TEXT;
ALTER TABLE users ADD COLUMN bio TEXT NOT NULL DEFAULT '';