-- Latch schema v2 — report queue, quarantine, warnings (Phase 2)
PRAGMA foreign_keys = ON;

ALTER TABLE reports ADD COLUMN severity TEXT NOT NULL DEFAULT 'medium';
ALTER TABLE reports ADD COLUMN reason_code TEXT NOT NULL DEFAULT 'other';
ALTER TABLE reports ADD COLUMN reason_detail TEXT NOT NULL DEFAULT '';
ALTER TABLE reports ADD COLUMN resolution_action TEXT;
ALTER TABLE reports ADD COLUMN quarantine_applied INTEGER NOT NULL DEFAULT 0;

UPDATE reports SET reason_detail = reason WHERE reason_detail = '' AND reason != '';

ALTER TABLE posts ADD COLUMN quarantined_at TEXT;
ALTER TABLE posts ADD COLUMN quarantined_by_report_id INTEGER REFERENCES reports(id);

CREATE TABLE IF NOT EXISTS user_warnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    issued_by INTEGER NOT NULL REFERENCES users(id),
    report_id INTEGER REFERENCES reports(id) ON DELETE SET NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_user_warnings_user ON user_warnings (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_reports_open_severity ON reports (status, severity, created_at);