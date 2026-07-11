-- Phase 4: plugin enable list (JSON array of slugs in settings)
-- Policy: bundled plugins under plugins/ ship disabled on new installs (empty array).
-- INSERT OR IGNORE preserves operator choices across upgrades — never auto-enable new bundled plugins.
INSERT OR IGNORE INTO settings (key, value) VALUES ('enabled_plugins', '[]');