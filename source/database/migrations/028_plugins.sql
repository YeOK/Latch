-- Phase 4: plugin enable list (JSON array of slugs in settings)
INSERT OR IGNORE INTO settings (key, value) VALUES ('enabled_plugins', '[]');