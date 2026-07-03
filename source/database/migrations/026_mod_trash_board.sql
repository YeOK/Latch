-- Staff-only moderation trash board (empty until posts are archived).

INSERT INTO boards (
    slug,
    name,
    description,
    sort_order,
    requires_login_to_read,
    staff_only_topics,
    acl_read,
    acl_topic,
    acl_reply,
    icon_key
)
SELECT
    'mod-trash',
    'Moderation trash',
    'Removed posts and deleted topics for staff review.',
    (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM boards),
    1,
    1,
    'mod',
    'admin',
    'admin',
    ''
WHERE NOT EXISTS (SELECT 1 FROM boards WHERE slug = 'mod-trash');

INSERT INTO settings (key, value)
SELECT 'moderation_trash_board_id', CAST(id AS TEXT)
FROM boards
WHERE slug = 'mod-trash'
  AND NOT EXISTS (
      SELECT 1 FROM settings WHERE key = 'moderation_trash_board_id'
  );