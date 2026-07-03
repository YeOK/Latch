-- Per-board ACL: minimum role for read / new topic / reply
ALTER TABLE boards ADD COLUMN acl_read TEXT NOT NULL DEFAULT 'guest';
ALTER TABLE boards ADD COLUMN acl_topic TEXT NOT NULL DEFAULT 'member';
ALTER TABLE boards ADD COLUMN acl_reply TEXT NOT NULL DEFAULT 'member';

UPDATE boards SET acl_read = 'member' WHERE requires_login_to_read = 1;
UPDATE boards SET acl_topic = 'mod' WHERE staff_only_topics = 1;