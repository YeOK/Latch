-- Optional per-board icon key (empty = auto-match from default pack).
ALTER TABLE boards ADD COLUMN icon_key TEXT NOT NULL DEFAULT '';