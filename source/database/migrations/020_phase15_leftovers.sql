-- Phase 1.5 leftovers: email change confirmations

CREATE TABLE IF NOT EXISTS email_change_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    new_email TEXT NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_change_token ON email_change_requests (token_hash);
CREATE INDEX IF NOT EXISTS idx_email_change_user ON email_change_requests (user_id, expires_at);