-- Retention for self-deleted accounts (daily cron hard-purges after N days).

INSERT OR IGNORE INTO settings (key, value) VALUES ('cron_deleted_user_retain_days', '30');