-- Phase 5: optional outbound mail queue for notification email bursts

CREATE TABLE IF NOT EXISTS mail_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    queued_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    sent_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_mail_queue_pending
    ON mail_queue (queued_at ASC)
    WHERE sent_at IS NULL;

INSERT OR IGNORE INTO settings (key, value) VALUES ('mail_queue_enabled', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('mail_queue_batch_size', '50');
INSERT OR IGNORE INTO settings (key, value) VALUES ('mail_queue_max_attempts', '5');