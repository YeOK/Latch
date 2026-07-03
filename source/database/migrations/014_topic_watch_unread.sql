-- Phase 3: topic watch/bookmark + per-user read tracking

CREATE TABLE IF NOT EXISTS topic_watches (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, topic_id)
);

CREATE TABLE IF NOT EXISTS topic_reads (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    last_read_post_id INTEGER,
    last_read_at TEXT NOT NULL,
    PRIMARY KEY (user_id, topic_id)
);

CREATE INDEX IF NOT EXISTS idx_topic_watches_user ON topic_watches (user_id);
CREATE INDEX IF NOT EXISTS idx_topic_reads_user ON topic_reads (user_id);