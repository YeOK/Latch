-- Phase 6: phpBB (and future) import ID mapping
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS import_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    entity TEXT NOT NULL,
    source_id INTEGER NOT NULL,
    target_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (source, entity, source_id)
);

CREATE INDEX IF NOT EXISTS idx_import_map_target ON import_map (source, entity, target_id);