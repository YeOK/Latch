-- Phase 3: post edit revision history

CREATE TABLE IF NOT EXISTS post_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    editor_id INTEGER NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_post_revisions_post_created
    ON post_revisions (post_id, created_at DESC);

INSERT OR IGNORE INTO settings (key, value) VALUES ('post_edit_window_minutes', '60');