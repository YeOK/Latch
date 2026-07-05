# Changelog

All notable changes to [Latch](https://latch.network) are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0.14] — 2026-07-05

### Changed
- **Copyright headers** — MIT `SPDX-License-Identifier` notices added to first-party PHP, shell scripts, and theme/plugin assets; `scripts/add-copyright-headers.py` backfills new files.
- **Public tree hygiene** — `.gitignore` blocks operator deploy symlinks (`latch-logs.sh`, `setup-api-test-client.sh`, etc.); test fixtures and docs examples use generic usernames instead of operator handles (COPR/git `yeok` paths unchanged).
- **Account deletion** — self-deleted users set `deleted_at` instead of `banned_at`; admin **Users** has a **Deleted** tab separate from **Banned**; migration `032` backfills existing self-deleted rows; daily cron hard-purges self-deleted accounts after **30 days** (`cron_deleted_user_retain_days`) while posts/topics remain (author shows as `[deleted]`).
- **`db-check`** — `foreign_key_check` ignores expected author orphans (`posts` / `topics` / `post_revisions` → missing `users` row) after retention purge; real FK problems still fail the gate.
- **Install / security bootstrap** — `bin/latch install` writes `security.encryption_key` into new `local.php` and runs `security-bootstrap` when the key is still missing; `doctor` fails on installed instances without a valid key; **INSTALL.md** documents 2FA and bootstrap in the first-deploy path.
- **First-time install script** — `scripts/install.sh` wraps Composer, `bin/latch install`, `doctor`, and optional cron for tarball/git installs; cron template ships at `scripts/cron/latch.cron.example` (fixes broken `install-cron.sh` on public trees).
- **Operator preflight** — `doctor` warns when daily cron has not run in 48h; admin **Deleted** tab shows retention from `cron_deleted_user_retain_days`; `UserDependencyCleanup` clears `issued_by` / `editor_id` / OAuth client / trash staff references on purge.
- **Release gates** — smoke suite adds account deletion, profile delete, report queue, DMs, 2FA cancel, and doctor checks; security suite adds OIDC authorization URL tests; `LATCH_TEST_URL` / `LATCH_URL` enable HTTP smoke without a config file.
- **CONTRIBUTING.md** — contributor setup, test gates, and release notes for OSS onboarding.

### Fixed
- **Two-factor sign-in** — “Back to sign in” clears the pending 2FA session instead of looping back to the code prompt.
- **New topic composer** — no longer pre-fills from a stale quote/reply draft or browser `body` field cache; quote-shaped localStorage drafts are discarded; draft clears after a successful post.

## [0.3.0.13] — 2026-07-05

### Added
- **Direct messages** — delete empty conversations (trash icon in thread header); per-message delete on your own messages is easier to spot on mobile.
- **Post editor** — toolbar adds bullet list, heading, and @mention helpers plus a `?` markup cheat sheet; existing buttons show syntax in tooltips.
- **Code blocks** — live AJAX preview under the composer (no Write/Preview tab); Code button inserts a fenced block and a language dropdown appears while the cursor is inside it; topic posts highlight fenced blocks with highlight.js; language label shown on read view; editor uses a 70/30 split with scroll-synced preview; textarea is vertically resizable while the preview pane stays fixed.

### Changed
- **Theme performance budgets** (`docs/THEMING.md`) — raised compose `editor.js` cap to 32 KB (live preview, code blocks, reply flow); documented `highlight.min.js` and refreshed CSS/staff size targets to match the default theme.
- **Footer** — language selector moved from Explore to Operator column.
- **README screenshots** — refreshed boards home and admin dashboard images from latch.network.
- **INSTALL.md / INSTALL-FEDORA.md** — drop pre-COPR `sync-latch` / `publish-latch-server` wording; point vhost and permissions fixes at public paths (`packaging/latch-httpd.conf`, `scripts/fix-latch-storage-perms.sh`).

### Fixed
- **Topic reply** — Reply no longer restores a stale quoted draft from localStorage (non-quote drafts are still restored); Quote still pre-fills the attributed quote; reply draft clears after submit.
- **Code highlighting (dark theme)** — syntax colours use a high-contrast dark palette so keywords and strings stay readable on dark backgrounds.
- **Header alignment** — theme toggle and sign-in / user menu align to the right edge of the main content column (matches boards Topics/Posts stats track); search bar centred in the header on desktop (equal side tracks in a 3-column grid).
- **Release hygiene** — `router-dev.php` moved out of `source/public/` to `scripts/router-dev.php` (dev-only PHP built-in server router); excluded from release tarball and COPR `%install` so it is not web-accessible on production installs.

## [0.3.0.12] — 2026-07-04

### Added
- **phpBB import (Phase 6 v1)** — `bin/latch import phpbb` with `--dry-run` / `--confirm` (JSON bundle) and `--export --from-mysql=` (requires `pdo_mysql`); `BbcodeConverter`, `import_map` migration `031`, fixture bundles under `scripts/fixtures/phpbb/`.
- **Phase 5 test gates** — `bin/latch test --smoke` and `test --security` run dedicated PHPUnit suites (`phpunit.xml.dist`), then `db-check` / `audit`; optional live HTTP probes with `--url=` or `tests/smoke/config.local.php`; smoke also runs `tests/api/` harness when API config exists.
- **`CsrfTest`**, **`SecurityRegressionTest`** — CSRF rotation/validation and markup/SSRF regression coverage in the security suite.
- **Outbound URL guard** — webhook create/delivery rejects private, loopback, and link-local targets (SSRF mitigation).
- **Plugin enable gate** — markup/JS injection audit warnings now block enable (stricter than audit pass).
- **Theme manifest note** — `themes/default/README.md` documents that `theme.json` is not loaded at runtime.

### Fixed
- **OIDC registration** — new Google/GitHub sign-ups now respect **registration disabled** and per-IP signup rate limits; linking existing accounts is unchanged.
- **`md-import` + `image-upload`** — `BodyGuard` no longer treats markdown image syntax inside inline or fenced code as real post images, so documentation like `docs/PLUGINS.md` imports successfully when `image-upload` is enabled.
- **`md-import` admin form** — import uses a full-page POST (`data-account-bypass`) so file uploads and hook rejection flashes are reliable; admin SPA now navigates away when a form redirects outside `/admin` (e.g. to the new topic).

### Changed
- **Locale quick switch** — `POST /locale` with CSRF replaces `GET /locale/{code}`.
- **TOTP enrollment** — requires `security.encryption_key` in `config/local.php` (derived-key encrypt is decrypt-only for legacy secrets).
- **CSRF rotation** — new token after login and sensitive profile/2FA mutations.
- **Moderation trash** — shared restore/purge logic for admin and mod controllers.

### Removed
- Dead code: `BoardIconProviderInterface`, unused `Application::router()` / `hooks()`, sort `label()` helpers, orphan `field_input.html.twig` partial.

## [0.3.0.11] — 2026-07-04

### Added
- **Bulk topic moderation** — moderators can multi-select topics on board pages to pin, unpin, lock, unlock, or remove in one action (shield toggle on the topic list).
- **Post sort on topics** — sort replies by oldest first, newest first, or most likes; control sits below the mod shield button, matching the board layout.
- **Delete all mod trash** — admins can permanently purge the entire moderation trash queue from **Admin → Maintenance** (with confirmation).

### Changed
- **Board topic sort** — sort dropdown moved to the right column below the mod shield (no separate “Sort” label).
- **Topic quick actions** — pin, lock, and remove buttons moved beside the mod shield on topic pages; watch stays in the header.

## [0.3.0.10] — 2026-07-04

### Fixed
- **Admin 500 after upgrade** — stale PHP-FPM opcache could still run the pre-0.3.0.9 `HookRegistry` after `dnf upgrade`, breaking plugin sidebar links (`md-import`). RPM `%posttrans` now restarts `php-fpm`; admin layout skips non-mapping menu entries defensively.

## [0.3.0.9] — 2026-07-04

### Added
- **`md-import` operator plugin** — admin upload/paste of `.md` files to create formatted topics (GitHub-style rendering); lives under **Admin → Import markdown** with in-panel SPA navigation. Excluded from public release tarballs; shipped in git/COPR for operator installs.

### Fixed
- **Plugin admin menu** — `admin.menu` hooks returning a single `{label, href}` item no longer break the admin layout (500 on strict Twig).

### Changed
- **Admin dashboard** — added Topics, Boards, and Open reports stat cards; new **System** panel (database size with WAL breakdown, guest cache, last cron runs, mail status) sits below forum stats and above the version panel.
- **Footer about text** — site-specific copy below the footer logo is editable in **Admin → Settings** (below Tagline). Single line breaks and blank lines between paragraphs are preserved in the footer. Leave empty to fall back to the tagline. Fresh installs seed the previous default Latch marketing text.
- **Example plugins** — `example`, `badexample`, and `warnexample` moved from `plugins/` to `docs/plugins/` so they are not auto-discovered; copy into `plugins/{slug}/` when needed. Active bundled plugins remain `forum-stats` and `image-upload`.
- **Plugin admin pages** — document registering admin UI under `/admin/…` for SPA in-place loading (`PLUGINS.md`).

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

[0.3.0.13]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.13
[0.3.0.12]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.12
[0.3.0.1]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1
[0.3.0]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1