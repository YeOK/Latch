# Database example plugin

Reference plugin for **per-plugin SQLite** (`storage/plugins/dbexample/plugin.sqlite`).

## What it demonstrates

- Manifest `"database": { "enabled": true }`
- SQL migrations under `migrations/` applied on `plugin enable` and app boot
- Reading the DB via `$context->database()->pdo()` in hook callbacks

## Try locally

```bash
php bin/latch plugin install docs/plugins/dbexample
php bin/latch plugin enable dbexample
```

After enable, `storage/plugins/dbexample/plugin.sqlite` exists with an `event_log` table.

## spam-bridge pattern

Plugins that need durable logs (e.g. `spam_log` for Akismet rejects) ship a migration like:

```sql
CREATE TABLE IF NOT EXISTS spam_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    provider TEXT NOT NULL,
    user_id INTEGER,
    post_id INTEGER,
    reason TEXT NOT NULL,
    payload TEXT
);
CREATE INDEX IF NOT EXISTS idx_spam_log_created_at ON spam_log(created_at);
```

Core never adds plugin tables to `latch.sqlite` — only `plugin.sqlite` under `storage/plugins/{slug}/`.