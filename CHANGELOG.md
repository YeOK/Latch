# Changelog

All notable changes to [Latch](https://github.com/YeOK/Latch) are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work in progress on `main` — not tagged or released yet. Fold into the next version section before `scripts/build-release.sh`.

### Fixed
- **Composer live preview** — skips plugin link cards (link-preview) and inline images (image-upload); markdown images show a compact `[image]` placeholder; smileys, spoilers, code, and other markup still render.

## [0.4.4.0] — 2026-07-12

### Added

- **Plugin hook expansion (28 hooks total)** — 11 new hooks for tier-1/2 plugins:
  - `post.format.link`, `post.format.after` — link preview / onebox at render time
  - `csp.frame_src`, `csp.script_src` — video embeds and analytics scripts
  - `layout.head` — `<head>` snippets (privacy analytics)
  - `topic.actions` — topic header share buttons (fediverse-share)
  - `profile.form`, `profile.before_save` — avatar-url and profile extensions
  - `post.delete`, `topic.delete`, `post.vote` — lifecycle hooks for plugin cleanup and reactions
- **Standalone bare `https://` URLs** — auto-linked when alone on a line; `post.format.link` receives `$standalone = true`
- **`ProfileSaveContext`** — mutable context for `profile.before_save` (mirrors `PostSaveContext`)
- Docs: `PLUGINS.md` hook reference updated; `MARKUP.md` standalone URL note

## [0.4.3.1] — 2026-07-12

### Added
- **Admin plugins UI** — **Installed** / **Catalog** tabs with icon actions (install, enable, disable, settings, remove); SPA-friendly `?tab=` navigation.
- **`fix-perms` overhaul** — repairs all runtime paths (`storage/`, `plugins/` code, SQLite sidecars, `local.php`); `--web-user` / `WEB_USER` override (default `apache`).
- **`docs/RELEASE.md`** — full release checklist (VERSION, RPM, tarball, git tag, COPR); `scripts/check-versions.sh` gate in `build-release.sh`.

### Fixed
- **GitHub release zip download** — follow HTTP 302 redirect chain (GitHub → `release-assets.githubusercontent.com`).
- **Catalog install permissions** — `plugins/` parent writable by web server; RPM `%post` sets `apache:apache` on `plugins/`.
- **`sudo latch fix-perms` / `plugin remove`** — wrapper stays root for chown and code deletion under `/usr/share/latch/`.
- **Admin plugins 500** — tab query uses `Request::input()` (not missing `query()` method).

### Changed
- **Remove control** — trash icon beside plugin title; catalog install stays in-panel AJAX (no full-page bypass).

## [0.4.3.0] — 2026-07-12

### Added
- **Admin catalog plugin install** — **Admin → Plugins** lists the [Latch-plugins](https://github.com/YeOK/Latch-plugins) catalog and installs release zips with the same audit gate as `plugin install` (plugin stays disabled until you enable it).
- **`php bin/latch totp reset <username> --confirm`** — operator recovery when 2FA codes fail after an `encryption_key` mismatch (documented in `CLI.md`, `SECURITY.md`, `INSTALL.md`).
- **`PluginCatalog` / `PluginReleaseDownloader`** — fetches `catalog.json`, caches it, downloads GitHub release assets (API fallback when the direct zip URL 404s).

### Fixed
- **Admin SPA plugin actions** — error flashes from catalog install are no longer wiped by an immediate in-panel reload; catalog install uses a full-page POST (`data-account-bypass`).
- **Staff JSON errors** — failed admin AJAX actions now also set session flash so messages survive navigation.

## [0.4.2.0] — 2026-07-11

### Added
- **`bin/latch fix-perms`** — one-shot repair for root-owned `storage/plugins/` and `storage/cache/plugin-audits/` (use after a bad `sudo php bin/latch plugin …`).
- **Plugin auditor PSR-4 check** — CRITICAL finding when a plugin class path does not match `PluginLoader` autoload layout (blocks enable).
- **Plugin auditor runtime checks** — CRITICAL findings for root-owned or non-writable plugin storage on **installed** plugins under `plugins/`.
- **`PluginStoragePermissions`** — when `plugin enable` runs as root, chown `storage/plugins/{slug}/` to the web server user.

### Fixed
- **`bin/latch audit`** — includes full Doctor layer-4 permission checks (plugin storage, audit cache, world-readable DB/config); install and upgrade gates catch permission regressions.
- **Plugin settings / audit cache writes** — clearer errors pointing at `sudo latch fix-perms` and the RPM `latch` wrapper.
- **spam-bridge** (Latch-plugins **1.0.2**) — PSR-4 autoload split (`AppRegistrationEnforcer.php`), reload `settings.json` on each check so provider changes apply without disable/enable.

### Changed
- **`scripts/install.sh`** — runs `doctor` and `audit` as hard gates (no longer ignores doctor failures); prints RPM plugin permission reminders.
- **Audit / update failures** — actionable “How to fix” hints (`fix-perms`, `doctor`, `sudo latch plugin enable`).
- **Docs** — production plugin permissions in `PLUGINS.md`, `INSTALL.md`, `INSTALL-FEDORA.md`, and `CLI.md`.

## [0.4.1.0] — 2026-07-11

### Added
- **Latch-plugins catalog v1.0.0** — tier 1 plugins (`forum-stats`, `image-upload`, `word-filter`, `spam-bridge`, `slack-notify`) published at [github.com/YeOK/Latch-plugins](https://github.com/YeOK/Latch-plugins) with per-plugin and bundle release zips.
- **PR-P6 manifest cache** — `cache` object in `plugin.json`; `tagPlugin:{slug}` invalidation; bake / fragment / client / bypass guest modes; `PluginCollectContext`; CLI enable/disable busts plugin cache.
- **PR-P3 plugin database** — per-plugin `plugin.sqlite`, `PluginMigrator`, migrations on enable/boot; `docs/plugins/dbexample/` reference.
- **spam-bridge** — Akismet + Stop Forum Spam on `post.before_save` / `user.register`; `spam_log` in plugin SQLite (catalog).
- **slack-notify** — Slack/Discord incoming webhooks on `post.after_save` / `user.register` (catalog).

### Fixed
- **PluginCollectContext fatal** — `Application::resolvedLocale()` and `cspNonce()` are public so the app boots with PR-P6; regression test added.
- **Guest cache bypass** — `guest_page: bypass` now disables fragment cache as well as full page cache.

### Changed
- **Plugin packaging** — tier 1 plugins removed from core `plugins/`; install via `plugin install` from catalog zips. Operator `md-import` excluded from public tarball.
- **PR-P4 image-upload** — operator toggles (`max_mb`, `key_prefix`, `allowed_types`) in admin settings / `settings.json`; R2 secrets remain in `local.php` only.
- **PHPUnit** — catalog plugins resolved from sibling `Latch-plugins/` (or `LATCH_PLUGINS_CATALOG`) for plugin tests.
- **Release build** — exclude internal `docs/design/` and `docs/RELEASE-v0.3.0.md` from public tarball.

## [0.4.0.0] — 2026-07-11

### Added
- **Mail queue** — optional `mail_queue` table and worker for notification email bursts; enabled in Admin → Settings; drained by `cron hourly` or `php bin/latch mail process`. Auth emails (verify, reset, email change) stay synchronous.
- **Plugin install/remove** — `php bin/latch plugin install <dir|zip>` copies into `plugins/{slug}/` with audit gate (rollback on critical); `plugin remove <slug> --confirm` deletes installed copy; optional `--purge-storage`.
- **Word filter plugin** — bundled `plugins/word-filter/` blocks or masks profanity on `post.before_save`; ships basic blocked-word list; Aho-Corasick matcher; staff bypass; code fences ignored.
- **Plugin admin settings** — `settings_schema` / `secrets_schema` in `plugin.json`; generic form at `/admin/plugins/{slug}/settings`; `PluginSettingsStore` merges defaults with `storage/plugins/{slug}/settings.json`; word-filter is the first consumer.
- **Plugin catalog repo** — [github.com/YeOK/Latch-plugins](https://github.com/YeOK/Latch-plugins) created for future distributable plugins and GitHub Release zips; admin catalog install not implemented yet (`plugin install` local dir/zip remains).
- **Bundled plugin install policy** — documented and enforced: `"bundled": true` in manifest; `enabled_plugins` defaults to `[]` on new installs; upgrades preserve operator enable/disable state (migration 028 `INSERT OR IGNORE`).

## [0.3.0.23] — 2026-07-10

### Added
- **Fragment cache** — guest HTML fragments for home board panels and board topic lists (`storage/cache/fragments/`); same tag invalidation as page cache.
- **Large-topic pagination** — cursor-based post chunks above `forum.topic_pagination_threshold` (default 50); `GET /topic/{id}/posts?after=` load-more; `?latest=1` jump to tail.
- **SQLite scale guide** — operator limits and migration notes in `docs/PERFORMANCE.md`.
- **CDN guide** — Cloudflare cache rules in `docs/CDN.md`.

## [0.3.0.22] — 2026-07-10

### Fixed
- **Version display** — `/health`, admin dashboard, and plugin compatibility read the tree `VERSION` file first (RPM: `/usr/share/latch/VERSION`), with `app.version` in config as fallback. Fixes production showing `0.3.0.20` after deploying `0.3.0.21` when `default.php` was not bumped.

## [0.3.0.21] — 2026-07-10

### Security
- **Open redirect** — `Request::safeRedirectFromReferer()` requires exact host match with `site.url`; `safeRedirectPath()` tightens path allowlist. `LocaleController` and rate-limit redirect in `ReportController` use the helper. Regression tests in `SecurityRegressionTest`.

### Fixed
- **Accessibility (light theme)** — badge and footer link contrast via `--badge-fg` / `--footer-link-fg`; `.board-panel-view-all` uses `--accent-hover` for WCAG 4.5:1 on white surfaces (default + modern themes).
- **GDPR / Gravatar** — third-party avatars load only after `latch_cookie_consent=accepted` in EU hosting mode; identicon placeholders with deferred `data-gravatar-src` until accept. `CookieConsentGate` + updated cookie policy copy (all locales).

### Changed
- **PHPUnit configs** — split smoke/security into `phpunit-smoke.xml.dist` and `phpunit-security.xml.dist`; `bin/latch test` picks config by suite (eliminates duplicate-suite warnings).
- **Public docs & templates** — replace operator-specific `latch.network` examples with `forum.example.com`; footer uses `site.url` and GitHub for “Powered by Latch”; release build rejects `latch.network` / `images.latch.network` in staged `source/`.

## [0.3.0.20] — 2026-07-07

### Fixed
- **Messages overlay (i18n)** — `messages-panel.js` defined `LatchI18n` only inside a click handler while `loadPanel()`/`closePanel()` referenced it at module scope; opening Messages threw `ReferenceError: i18n is not defined` and AJAX feeds never ran (v0.3.0.19 regression).
- **Admin overlay history** — closing the account/admin panel after in-panel navigation now walks back the full `history` stack (`overlayPushDepth`) so refresh no longer lands on the last admin URL.
- **Header vs main width** — public header, main, and footer share one column rule (`.latch-header .header-bar` included); header no longer renders wider than topic/board content when guest cache omits `page-forum`.
- **Pinned badge contrast** — dark theme badge tokens (`--badge-fg` / `--badge-bg`) meet WCAG contrast on pinned labels (default + modern themes).

### Changed
- **`md-import` images** — HTML `<img>` / `<picture>` tags and foreign-host markdown images convert to a CDN placeholder (`/.md-import/REPLACE-ME.png`) so imports pass `image-upload` BodyGuard and can be swapped in the editor.
- **Apache packaging example** — `packaging/latch-httpd.conf` documents `ServerAlias www.forum.example.com`.

## [0.3.0.19] — 2026-07-05

### Fixed
- **Forum layout width (board vs topic)** — public `main` and footer width no longer depend on the `page-forum` body class, so board and topic/post views stay aligned with the header even when guest page cache serves stale HTML without `page-forum` (default + modern themes).
- **Home page (members-only lead)** — Twig `replace` map no longer uses an invalid dynamic key; fixes HTTP 500 on `/` when registration is disabled.

### Added
- **Internationalization (Phase 7)** — guest/member Twig templates, legal pages, profile and auth flows, board/topic sort labels, and first-party JS overlays (`window.LatchI18n`) use `lang/{locale}.php` via `trans()`. Catalog: `en`, `es`, `de`, `fr`, `ar` (complete Arabic; English fallback for missing keys). RTL layout hooks for Arabic in default `theme.css`. `LocaleCatalogTest` asserts key parity across locale files.
- **PHP i18n (Phase 8)** — `NotificationMessageFormatter` localizes notification feed/page text at read time (`notify.*` keys); report reason labels use `report.*`; `Application::flashTrans()` + `FlashMessage` key constants for controller migration; `api.*` for JSON errors. Notification `meta_json` now stores `topic_title` on new events.

### Changed
- **Admin plugins audit column** — pass/fail shown as icons; scan timestamp and cache status moved to hover tooltip.
- **RPM upgrades** — `packaging/latch-rpm-update` runs `scripts/update.sh --clear-cache` so `dnf upgrade latch` purges guest HTML after deploys (theme/layout fixes take effect without a manual `cache-clear`).
- **Packaging hygiene** — `source/vendor/` removed from git; `composer.lock` is the source of truth. COPR `%build`, `scripts/build-release.sh`, and `scripts/install.sh` run `composer install --no-dev` (bundled `source/composer.phar` fallback). Release tarballs still ship a populated `vendor/`.

## [0.3.0.18] — 2026-07-05

### Changed
- **Forum layout width** — `page-forum` now aligns the site footer with header and main column on both default and modern themes (`body.page-forum :is(main.container, .site-footer .container)`).
- **COPR/RPM builds** — `%build` runs `composer install --no-dev` when mock builds have network access (`BuildRequires: composer`); release tarballs still ship `vendor/` (built by `build-release.sh`).

## [0.3.0.17] — 2026-07-05

### Changed
- **Forum layout width** — home, board, topic, tag, search, and watched pages share one content track (`page-forum`); header and main column stay aligned on all forum views.

### Fixed
- **`app.version` display** — `config/default.php` stays in sync with `VERSION` / git tag (fixes `/health` and admin version panel showing 0.3.0.15 after 0.3.0.16 RPM deploy).
- **Header consistency** — site header uses the same width and desktop height on every public page, not only the home page.

## [0.3.0.16] — 2026-07-05

### Changed
- **Bulk topic moderation (scale)** — `BulkTopicActionService` defers guest-cache and FTS side effects to one flush per request; `ModerationTrashBatch` batches search removals on bulk delete; board UI sends large selections in chunks of 20 with progress (`board-mod-tools.js`); per-topic author notifications skipped in bulk (audit log unchanged); **Delete all mod trash** uses batched cache invalidation. Documented in [PERFORMANCE.md](source/docs/PERFORMANCE.md#bulk-topic-moderation).
- **Lighthouse (guest home page)** — Chrome Lighthouse on dev (`127.0.0.1:8080`, 2026-07-05): Performance **100**, Accessibility **100**, Best Practices **100**, SEO **100**. Production baseline before this release (v0.3.0.15): 100 / 94 / 81 / 100.

### Fixed
- **CSP on cached guest pages** — guest page-cache hits rewrite inline `nonce` attributes to match the per-request CSP header (fixes theme FOUC script blocked in Lighthouse/DevTools).
- **Admin users bulk script** — inline bulk-action script includes CSP nonce.
- **Lighthouse accessibility** — header brand link uses visible text for its accessible name (drops redundant `aria-label`); footer inline links are underlined so they are distinguishable without relying on color alone.
- **PHP 8.5 deprecations** — `Database` uses `Pdo\Sqlite::OPEN_READONLY` when available; `BoardAcl::viewerLevel` no longer indexes `LEVELS` with a null role.

## [0.3.0.15] — 2026-07-05

### Added
- **SQLite connection tuning** — configurable `database.sqlite` PRAGMAs in `config/default.php` (`busy_timeout_ms`, `cache_size_kib`, `mmap_size`); override in `config/local.php`; documented in [INSTALL.md](source/docs/INSTALL.md).
- **Theme JS security scan** — `ThemeJsAuditor` in `test --security` for first-party `themes/default/assets/js/` (complements GitHub CodeQL); see [TESTING.md](source/docs/TESTING.md).
- **Plugin audit cache** — results stored under `storage/cache/plugin-audits/`; admin **Plugins** reuses cache when files unchanged; `cron daily` refreshes all non-ignored plugins.
- **Plugin ignore (CLI)** — `php bin/latch plugin ignore <slug>` sets `"ignored": true` in `plugin.json`, disables the plugin, and excludes it from discovery, audits, and admin UI; `plugin unignore` / `plugin list --all` restore visibility.

### Changed
- **Plugin audits** — admin page no longer re-scans every plugin on every load; enable and `plugin-audit` still force a fresh scan.
- **Documentation** — `PLUGINS.md`, `CLI.md`, `TESTING.md`, `PERFORMANCE.md`, `SECURITY.md`, `CONTRIBUTING.md`, and `local.php.example` updated for SQLite tuning, XSS gates, and plugin audit workflow.

### Fixed
- **`db-check` corrupt files** — truncated or malformed SQLite files return a failed report instead of throwing during read-only open (regression after configurable PRAGMAs).
- **New topic composer** — fixed regression where `restoreSavedDraft()` cleared then immediately re-applied the saved draft; new-topic forms no longer restore localStorage drafts, use readonly-until-focus to block browser autofill on `body`, and set `autocomplete="one-time-code"`.
- **DOM XSS (CodeQL)** — `account-panel.js`, `staff-actions.js`, `messages-panel.js` use safe DOM APIs instead of unsanitized `innerHTML` / `href` concatenation.

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
- **Admin UI localization** — admin templates remain English-only
- **Controller flash migration** — most `session()->flash()` calls still use hardcoded English; migrate to `flashTrans()` + `FlashMessage` incrementally
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

[0.4.4.0]: https://github.com/YeOK/Latch/releases/tag/v0.4.4.0
[0.4.3.1]: https://github.com/YeOK/Latch/releases/tag/v0.4.3.1
[0.4.3.0]: https://github.com/YeOK/Latch/releases/tag/v0.4.3.0
[0.3.0.23]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.23
[0.3.0.22]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.22
[0.3.0.21]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.21
[0.3.0.20]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.20
[0.3.0.13]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.13
[0.3.0.12]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.12
[0.3.0.1]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1
[0.3.0]: https://github.com/YeOK/Latch/releases/tag/v0.3.0.1