-- Moderation trash queue: mods remove posts to admin review; admins restore or purge.

ALTER TABLE posts ADD COLUMN trashed_at TEXT;
ALTER TABLE posts ADD COLUMN trashed_by_user_id INTEGER REFERENCES users(id);
ALTER TABLE posts ADD COLUMN trash_restore_topic_id INTEGER REFERENCES topics(id);
ALTER TABLE posts ADD COLUMN trash_restore_board_id INTEGER REFERENCES boards(id);

CREATE INDEX IF NOT EXISTS idx_posts_trashed_at ON posts (trashed_at);