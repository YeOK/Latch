-- When enabled, only staff (admin/mod) can create new topics on the board.
-- Members may still read and reply in existing threads.
ALTER TABLE boards ADD COLUMN staff_only_topics INTEGER NOT NULL DEFAULT 0;