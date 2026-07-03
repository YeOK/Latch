-- Latch schema v10 — spam controls (honeypot, link limits, mod approval queue)
ALTER TABLE posts ADD COLUMN approval_status TEXT NOT NULL DEFAULT 'approved'
    CHECK (approval_status IN ('approved', 'pending', 'rejected'));

CREATE INDEX IF NOT EXISTS idx_posts_approval_pending
    ON posts (approval_status, created_at)
    WHERE approval_status = 'pending' AND deleted_at IS NULL;