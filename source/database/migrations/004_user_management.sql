-- Latch schema — user management & topic moderation (Phase 2)
PRAGMA foreign_keys = ON;

ALTER TABLE topics ADD COLUMN deleted_at TEXT;
ALTER TABLE users ADD COLUMN banned_until TEXT;

CREATE INDEX IF NOT EXISTS idx_users_banned ON users (banned_at, banned_until);
CREATE INDEX IF NOT EXISTS idx_users_role ON users (role, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_topics_board_active ON topics (board_id, deleted_at, last_post_at DESC);