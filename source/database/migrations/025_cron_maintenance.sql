-- Phase 3.5: scheduled maintenance runs, reputation queue, notification prune index

CREATE TABLE IF NOT EXISTS maintenance_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job TEXT NOT NULL,
    ran_at TEXT NOT NULL,
    duration_ms INTEGER NOT NULL,
    stats_json TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_maintenance_runs_job_ran
    ON maintenance_runs (job, ran_at DESC);

CREATE INDEX IF NOT EXISTS idx_user_notifications_read_created
    ON user_notifications (read_at, created_at);

CREATE TABLE IF NOT EXISTS reputation_queue (
    user_id INTEGER PRIMARY KEY,
    queued_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO settings (key, value) VALUES ('cron_read_notification_retain_days', '90');
INSERT OR IGNORE INTO settings (key, value) VALUES ('cron_login_attempts_retain_days', '14');
INSERT OR IGNORE INTO settings (key, value) VALUES ('cron_notification_cap', '500');
INSERT OR IGNORE INTO settings (key, value) VALUES ('dm_retain_user_days', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('audit_log_retain_days', '365');