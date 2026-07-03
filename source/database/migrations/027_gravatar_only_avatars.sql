-- Avatars: Gravatar + identicon only; clear legacy custom avatar_url values.
UPDATE users SET avatar_url = NULL WHERE avatar_url IS NOT NULL;

INSERT OR IGNORE INTO settings (key, value) VALUES ('use_gravatar', '1');