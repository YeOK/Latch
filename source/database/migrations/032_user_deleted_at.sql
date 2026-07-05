-- Self-deleted accounts: dedicated flag (no longer reuse banned_at).

ALTER TABLE users ADD COLUMN deleted_at TEXT;

CREATE INDEX IF NOT EXISTS idx_users_deleted ON users (deleted_at);

-- Backfill accounts anonymised before this migration.
UPDATE users
SET deleted_at = banned_at,
    banned_at = NULL,
    banned_until = NULL,
    ban_reason = NULL
WHERE deleted_at IS NULL
  AND username GLOB 'deleted_[0-9]*'
  AND email GLOB 'deleted_*@deleted.local'
  AND banned_at IS NOT NULL;

UPDATE users
SET deleted_at = COALESCE(deleted_at, created_at)
WHERE deleted_at IS NULL
  AND username GLOB 'deleted_[0-9]*'
  AND email GLOB 'deleted_*@deleted.local';