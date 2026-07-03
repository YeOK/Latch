-- Phase 2 optional: ban reason, email notification preference

ALTER TABLE users ADD COLUMN ban_reason TEXT;
ALTER TABLE users ADD COLUMN notify_email INTEGER NOT NULL DEFAULT 1;