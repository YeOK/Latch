-- Phase 3: member reputation ranks (1–5); optional per-board min-rank gates

ALTER TABLE users ADD COLUMN reputation_score REAL;
ALTER TABLE users ADD COLUMN reputation_rank INTEGER;
ALTER TABLE users ADD COLUMN reputation_computed_at TEXT;
ALTER TABLE users ADD COLUMN rank_override INTEGER;

ALTER TABLE boards ADD COLUMN min_rank_read INTEGER;
ALTER TABLE boards ADD COLUMN min_rank_topic INTEGER;
ALTER TABLE boards ADD COLUMN min_rank_reply INTEGER;

CREATE TABLE IF NOT EXISTS reputation_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    score REAL NOT NULL,
    rank INTEGER NOT NULL,
    components_json TEXT NOT NULL,
    computed_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_reputation_snapshots_user
    ON reputation_snapshots (user_id, computed_at DESC);