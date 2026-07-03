-- Latch schema — FTS5 full-text search (Phase 2)

CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
    title,
    body,
    tags,
    topic_id UNINDEXED,
    board_id UNINDEXED,
    post_id UNINDEXED,
    tokenize='unicode61'
);

CREATE TABLE IF NOT EXISTS search_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    searched_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_search_attempts_ip_at ON search_attempts (ip_address, searched_at);