# Changelog

All notable changes to [Latch](https://latch.network) are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0.3] — 2026-07-03

### Fixed
- **Public docs** — removed operator-specific references (`yeok`, `henpen.dev`, private IPs) from `INSTALL.md`, `EMAIL.md`, `CLI.md`, and `UPGRADE.md`; dropped henpen msmtp fallbacks from `Mail` auto-detect.

### Changed
- **Release tarball** — `build-release.sh` now excludes `PLAN.md`, `deploy/forum-data/`, `deploy/msmtp.conf`, site-local fail2ban overrides, and private deploy scripts; fails the build if operator hostnames or paths remain in the staged tree.

## [0.3.0.2] — 2026-07-03

### Fixed
- **Fresh install migration order** — `001.5_security.sql` sorted before `001_initial.sql` under `sort()`, so new installs failed on `ALTER TABLE users` before the table existed. Renamed to `001a_security.sql`; existing databases that already recorded `001.5_security.sql` in `schema_migrations` are not re-applied.

## [0.3.0.1] — 2026-07-03

### Security
- **Removed leaked artifacts from release** — v0.3.0 accidentally shipped `source/storage/backups/*.tar.gz` (contained `config/local.php` + SQLite) and operator forum-post manifests under `source/data/`. **If you downloaded v0.3.0, rotate `security.encryption_key` on any install that shared those backup files** and treat the old key as compromised.
- `build-release.sh` now excludes `source/data/` and `source/storage/backups/*` and fails the build if either is present in the staged tree.

### Changed
- Operator forum-post JSON/MD lives under `deploy/forum-data/` (local only, never in git or tarball).

## [0.3.0] — 2026-07-03

**Withdrawn artifact** — use [0.3.0.1] instead. The v0.3.0 tarball contained dev backup archives and operator forum-post data; do not use it.

First **public** release. Latch is an MIT-licensed, self-hosted PHP + SQLite forum engine. Production has been running at [latch.network](https://latch.network); this tag is the sanitized open-source artifact.

### Added

#### Forum & UX
- Boards, topics, posts, Markdown-style formatting, spoilers, smileys, topic tags
- Full-text search (FTS5), RSS feeds, sitemap, guest page cache
- Light/dark theme, board icons, topic watch / unread state, post reactions (like/dislike)
- Direct messages with opt-in, staff warnings, and blocks
- In-app notifications and optional email copies
- User profiles, reputation ranks, board min-rank gates
- Report queue, quarantine, mod trash queue, user warnings
- Board ACLs (`guest` / `member` / `mod` read and post rules)

#### Security & auth
- Password login with account lockout counters and optional TOTP 2FA
- Password reset and email verification flows
- Session registry, security event log, CSP and security headers
- Admin audit log, login rate limiting (fail2ban-friendly HTTP 200 on failure)
- Site maintenance lock (blocks web + API during upgrades)
- OIDC social login hooks (Google/GitHub) — disabled until configured in `config/local.php`

#### API & integrations
- OAuth 2.0 API (`/api/v1/*`, client credentials and authorization code + PKCE)
- Outbound webhooks with signed deliveries
- i18n foundation (`lang/` files, locale switcher hooks)

#### Plugin system (Phase 4)
- Hook registry, `plugin.json` manifests, enable/disable via CLI and `/admin/plugins`
- Static `plugin-audit` scanner (PHP, markup, JS patterns) with audit gate on enable
- Bundled reference plugins:
  - `example` — minimal route + footer hook
  - `forum-stats` — home page totals panel (`home.after_boards`)
  - `image-upload` — R2 presigned direct upload + compose toolbar (no files in `storage/`)
  - `badexample` / `warnexample` — audit pass/fail fixtures for tests and docs

#### Operator CLI (`source/bin/latch`)
- `install`, `migrate`, `audit`, `backup`, `restore`, `db-check`, `update`, `lock`
- `cron hourly|daily|weekly` — DB prunes, reputation jobs, `ANALYZE` (never purges guest page cache on daily)
- `doctor` — PHP extensions, vendor, DB permissions, writable paths
- `test`, `test --smoke`, `test --security` — PHPUnit gates (+ `db-check` / `audit` on smoke)
- `plugin list|enable|disable`, `plugin-audit`, `api-client`, `search-reindex`, `benchmark`
- WAL-safe SQLite backup via `scripts/sqlite-backup.php`

#### Release & docs
- `VERSION` (0.3.0), `scripts/build-release.sh` (sanitized tarball + SHA256)
- `scripts/update.sh` — lock → backup → migrate → db-check → audit → unlock
- Operator docs: `source/docs/INSTALL.md`, `UPGRADE.md`, `CLI.md`, `PLUGINS.md`, `API.md`, `SECURITY.md`, `PERFORMANCE.md`, `TESTING.md`

#### Tests
- 216 PHPUnit tests (543 assertions) — forum core, plugins, backup/restore, cron, OAuth scopes, SQLite integrity

### Fixed
- **Mod topic delete confirm** — staff trash icon on the topic header no longer clips the “Remove this topic from the board?” popover off-screen; popovers use fixed viewport positioning and right-aligned header CSS
- **Theme asset cache busting** — `Application::assetVersion()` appends theme file mtimes so deploys invalidate CDN/browser caches without manually bumping `theme.asset_version`
- **Theme asset HTTP caching** — static CSS/JS served with `max-age=86400, must-revalidate` (removed `immutable`) so upgrades pick up new assets within a day
- **PHPUnit schema cache** — `Schema`, `UserRepository`, and `UserDependencyCleanup` key column/table caches per PDO connection (fixes false failures when tests swap in-memory databases)
- **PHPUnit fixtures** — cron, direct-message, and moderation tests aligned with current schema (`verified_at`, OAuth tables, `reports`, `banned_at`, etc.)

### Changed
- Avatars: Gravatar + identicon only in core; arbitrary HTTPS avatar URLs removed (optional future plugin)
- Bundled `forum-stats` and `image-upload` plugins use `assetVersion()` for their static assets
- Upgrades replace `source/app/`, `bin/`, and migrations; **`storage/`**, **`config/local.php`**, and **`plugins/`** are preserved

### Security
- Release tarball excludes `local.php`, live SQLite, logs, cache, and private deploy scripts
- `build-release.sh` fails if likely secrets are detected in staged `source/`
- Restore requires site lock by default; pre-restore snapshot on rollback path

### Known limitations (planned follow-ups)
- **Packagist / Docker** install layers — tarball + git clone for 0.3.0
- **phpBB import** — design only (`docs/design/phpbb-import.md`); Phase 6
- **i18n polish** — partial template coverage; Phase 7
- **OIDC E2E** — code ships; providers need operator credentials and manual checklist (`docs/TESTING.md`)
- **Custom avatar URL plugin** — optional; not bundled in 0.3.0

---

## Release checklist (operators)

```bash
# Build (maintainer)
./scripts/build-release.sh
sha256sum -c dist/SHA256SUMS

# Fresh install
tar -xzf latch-0.3.0.tar.gz && cd latch-*-stage/source
composer install --no-dev
php bin/latch install --url=https://forum.example.com --name="My Forum"

# Upgrade existing install
cd /var/www/latch && sudo bash scripts/update.sh
```

[0.3.0.1]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1
[0.3.0]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1