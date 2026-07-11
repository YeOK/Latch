# Latch CLI tools

Operator commands for installing, maintaining, and testing a Latch forum. Run from the `source/` directory:

```bash
cd /var/www/latch/source
php bin/latch help
```

## Quick reference

| Command | Purpose |
|---------|---------|
| `install` | First-time setup — database, config, admin user |
| `migrate` | Apply SQLite schema migrations |
| `audit` | Security self-check (permissions, headers, secrets) |
| `backup` | WAL-safe tarball `latch.sqlite` + `config/local.php` |
| `db-check` | SQLite `integrity_check` / `quick_check` + `foreign_key_check` (author orphans on `posts`/`topics`/`post_revisions` after account purge are expected) |
| `restore` | List or restore from `storage/backups/` (lock required) |
| `update` | Lock → backup → migrate → db-check → cron → audit → cache → unlock |
| `doctor` | Four-layer preflight — PHP, extensions, vendor, DB, permissions |
| `test` | Full PHPUnit suite (`Latch` testsuite) |
| `test --smoke` | Operator gate — smoke PHPUnit + `db-check` + `audit` [+ HTTP/API] |
| `test --security` | Security gate — security PHPUnit + `audit` [+ HTTP probes] |
| `cron hourly` | Rate-limit prunes, reputation queue flush, mail queue drain |
| `mail process` | Drain pending notification mail queue (same as hourly cron step) |
| `cron daily` | DB prunes, notifications, plugin security audits (cached), full reputation recompute (no cache purge) |
| `cron weekly` | ANALYZE, DM/topic_reads cleanup; `--audit` prunes audit_log |
| `reputation-recompute` | Manual full or per-user rank recompute |
| `plugin list [--all]` | List plugins and enabled state (`--all` includes ignored) |
| `plugin audit <path\|slug>` | Security scan (alias for `plugin-audit`) |
| `plugin enable <slug>` | Enable after audit pass (`--force` to override) |
| `plugin disable <slug>` | Disable a plugin |
| `plugin ignore <slug>` | Ignore plugin (CLI only — not in admin UI) |
| `plugin unignore <slug>` | Restore ignored plugin |
| `plugin-audit <path\|slug>` | Static security scan; `--json` for report |
| `maintenance` | Runs `cron daily` + optional `--clear-cache` / `--vacuum` |
| `lock on\|off\|status` | Site maintenance lock — blocks web + API (no DB traffic) |
| `search-reindex` | Rebuild FTS5 search index |
| `security-bootstrap` | Set `encryption_key` and re-wrap TOTP secrets |
| `api-client` | Create, list, or revoke OAuth API clients |
| `benchmark` | Curl timing report for key pages |
| `test-mail` | Send a test email (verify SMTP/msmtp) |
| `test-rss` | RSS unit tests + live feed validation (read-only DB or HTTP fallback) |
| `test-profiles` | Public profile unit tests |
| `test-spam` | Spam control unit tests |
| `test-webhooks` | Webhook repository unit tests (PHPUnit or built-in fallback) |
| `test-api` | Live REST API + OAuth smoke tests |
| `test-api-messages` | Messages API + user OAuth (PKCE); interactive |
| `import phpbb` | Import phpBB 3.3.x bundle into empty forum (Phase 6) |
| `post-announcements` | Post changelog replies to a forum topic |
| `purge-users` | Delete member accounts with no posts/topics |

---

## install

Create the database, write `config/local.php`, and create the first admin account.

```bash
php bin/latch install \
  --url=https://forum.example.com \
  --name="My Forum" \
  --admin-user=admin \
  --admin-email=admin@example.com
```

| Option | Default | Description |
|--------|---------|-------------|
| `--url` | `http://localhost` | Public site URL |
| `--name` | `Latch` | Site name |
| `--admin-user` | `admin` | Founder username |
| `--admin-email` | `admin@localhost` | Admin email |
| `--admin-pass` | *(prompted)* | Admin password |
| `--no-seed-board` | — | Skip creating the default General board |

---

## migrate

Apply pending SQL migrations from `database/migrations/`. Safe to re-run.

```bash
php bin/latch migrate
```

Run after every deploy that ships new migration files.

**SQLite tuning** — connection PRAGMAs (`busy_timeout`, `cache_size`, `mmap_size`) are configured in `config/default.php` → `database.sqlite` and can be overridden in `config/local.php`. See [INSTALL.md](INSTALL.md#sqlite-tuning-optional).

**`attempt to write a readonly database`** — SQLite needs **write permission on `storage/database/`** (for WAL/journal files), not just on `latch.sqlite`. On production the directory is often `750` and owned by `apache`:

```bash
sudo -u apache php bin/latch migrate
# or:
bash scripts/migrate-latch-db.sh
```

---

## doctor

Four-layer install preflight before `install`, after deploy, or as part of the release gate.

```bash
php bin/latch doctor
php bin/latch doctor --json
```

Checks PHP version (≥ 8.2), required extensions (`pdo_sqlite`, `mbstring`, `sodium`, …), `vendor/` presence, database install state, pending migrations, and `storage/` / SQLite file permissions. Warns when `php-xml` is missing (needed for `bin/latch test`).

---

## audit

Checks file permissions, encryption key, TOTP secret wrapping, and related security settings. Exits non-zero on failure.

```bash
php bin/latch audit
```

---

## backup

Creates a timestamped `.tar.gz` with the SQLite database and `config/local.php`.

```bash
php bin/latch backup
```

Prefer **`php bin/latch restore --latest`** (site lock required). See [UPGRADE.md](UPGRADE.md).

---

## db-check

SQLite structural health — separate from security `audit`.

```bash
php bin/latch db-check
php bin/latch db-check --quick          # faster scan
php bin/latch db-check --json
php bin/latch db-check --db=/path/to/copy.sqlite
```

| Exit | Meaning |
|------|---------|
| 0 | All checks passed |
| 1 | Corruption or FK violations |
| 2 | Database file missing |

Run after migrate, restore, or when you suspect corruption.

On production the database is often owned by `apache`. Plain `php bin/latch db-check` uses a read-only connection; if that still fails, the command copies to a temp file automatically. When in doubt:

```bash
sudo -u apache php bin/latch db-check
```

---

## restore

```bash
php bin/latch restore list
php bin/latch restore --latest
php bin/latch restore --name=latch-backup-20260703-120000.tar.gz
php bin/latch restore --archive=/path/to/backup.tar.gz
php bin/latch restore --latest --with-config   # also restore local.php (disaster recovery)
php bin/latch restore --latest --force         # skip lock gate (dangerous)
```

| Exit | Meaning |
|------|---------|
| 0 | Restored and db-check passed |
| 1 | Restore or post-restore check failed |
| 3 | Site not locked (use `lock on` or `--force`) |

Post-restore **db-check is mandatory** — command does not exit 0 until integrity passes. Failed restore attempts rollback from `storage/backups/.pre-restore-latest.sqlite` when possible.

---

## update

Orchestrates a safe upgrade **after** core files are on disk. Does not run `git pull` or Composer (use `scripts/update.sh` for that).

```bash
php bin/latch update --dry-run
php bin/latch update --skip-lock --skip-backup --assume-files-ready   # tail only (update.sh)
```

| Flag | Purpose |
|------|---------|
| `--skip-lock` | Operator manages lock manually |
| `--skip-backup` | Volume snapshot already taken |
| `--assume-files-ready` | Skip interactive pause (default when non-TTY) |
| `--skip-cron` / `--skip-audit` / `--skip-cache` | Skip tail steps |

On migrate or db-check failure, stderr prints rollback steps. Site stays locked until you `restore --latest` or fix manually.

Full runbook: [UPGRADE.md](UPGRADE.md).

---

## cron

Scheduled maintenance for production. **Does not purge guest page cache** — that stays on deploy or manual `maintenance --clear-cache`.

```bash
php bin/latch cron hourly
php bin/latch cron daily
php bin/latch cron weekly
php bin/latch cron weekly --audit   # also prune audit_log (e.g. monthly)
```

| Job | Schedule (prod template) | Reputation |
|-----|--------------------------|------------|
| `hourly` | Every hour at `:00` | Flushes `reputation_queue`; drains `mail_queue` when enabled |
| `daily` | `03:15` | Plugin security audits (cached under `storage/cache/plugin-audits/`), full `recomputeAll()` for all members |
| `weekly` | Sunday `04:30` | `ANALYZE`, DM/topic_reads prunes, **`foreign_key_violations`** count in log (0 = healthy) |

Stdout/stderr from crontab append to **`storage/logs/cron.log`**. After first deploy, confirm the log updates on the next hourly and daily run. Daily job stats (including `plugin_audits_*`) are also recorded in the `maintenance_runs` table.

Install the system crontab after first deploy:

```bash
bash scripts/install-cron.sh
# or: LATCH_ROOT=/var/www/latch/source WEB_USER=apache bash scripts/install-cron.sh
```

On production, cron and migrate must run as the web server user when SQLite is `apache`-owned:

```bash
sudo -u apache php bin/latch cron daily
sudo -u apache php bin/latch migrate
# or from the repo root on the server:
bash scripts/migrate-latch-db.sh
```

Retention defaults (override via `settings` table or admin UI): read notifications **90d**, login attempts **14d**, notification cap **500**/user, self-deleted accounts **30d**, audit log **365d**.

**Daily** also runs a **user orphan sweep** — deletes rows in token/session/DM/OAuth tables whose `user_id` (or DM participant) no longer exists. This heals leftovers from restores or manual SQL without relying on FK CASCADE alone. `bin/latch purge-users` uses the same dependency list when removing spam accounts.

---

## maintenance

Manual operator maintenance. Runs **`cron daily`** DB prunes, then optional cache purge or VACUUM.

```bash
php bin/latch maintenance
php bin/latch maintenance --clear-cache   # purge page + Twig cache
php bin/latch maintenance --vacuum        # SQLite VACUUM after prunes
```

Use `--clear-cache` after deploys or when stale HTML is suspected. Do **not** schedule `--clear-cache` nightly — it defeats guest page caching.

---

## lock (site maintenance mode)

File-based lock at `storage/site-lock.json`. While enabled, **all web and API requests** return **503** without opening SQLite. Cron jobs skip automatically. CLI commands (`migrate`, `backup`, `lock off`) still work.

**Recommended update sequence:**

```bash
php bin/latch lock on --message="Updating Latch"
php bin/latch backup
# deploy files / composer update
sudo -u apache php bin/latch update --skip-lock --skip-backup --assume-files-ready
# or: sudo bash scripts/update.sh  (lock + backup + update tail)
```

See [UPGRADE.md](UPGRADE.md) for rollback.

| Command | Purpose |
|---------|---------|
| `lock on` | Enable maintenance mode |
| `lock off` | Disable maintenance mode |
| `lock status` | Show lock state |
| `lock status --show-token` | Show lock state including unlock token (recovery if the admin UI token was missed) |

Enable from **Admin → Dashboard → Site lock**, or CLI. When enabling from admin, save the **unlock token** on `/admin/site-lock/enabled` — that page stays available while the rest of the site is locked. The main admin UI is unavailable until unlock.

Unlock without sudo: POST the token at `/maintenance/unlock` (no database required).

**Permission note:** locks enabled from the admin UI are created by the web server user (`apache`). Removing the lock file requires write access on `storage/` — same as `migrate`:

```bash
sudo -u apache php bin/latch lock off
```

If your login user is in the `apache` group and `storage/` is group-writable (`chmod 2770`), plain `php bin/latch lock off` works without sudo.

---

## Plugins (`plugin` / `plugin-audit`)

Customize Latch **without editing core** — see `docs/PLUGINS.md`. Installed plugins live in `plugins/{slug}/`. Enabled slugs are stored in `settings.enabled_plugins` (JSON). **Admin UI:** `/admin/plugins` (audit status, enable/disable, settings when `settings_schema` is declared). Distributable plugins will publish to **[github.com/YeOK/Latch-plugins](https://github.com/YeOK/Latch-plugins)** (catalog repo; admin install from Releases is future work).

### Quick reference

| Command | Purpose |
|---------|---------|
| `plugin list` | Discovered plugins, version, enabled/disabled, Latch compatibility |
| `plugin list --all` | Include ignored plugins |
| `plugin install <dir\|zip>` | Copy into `plugins/{slug}/`, audit gate, disabled by default |
| `plugin remove <slug> --confirm` | Disable and delete `plugins/{slug}/` |
| `plugin remove <slug> --confirm --purge-storage` | Also delete `storage/plugins/{slug}/` |
| `plugin audit <path\|slug>` | Run security scan (same as `plugin-audit`; updates cache) |
| `plugin enable <slug>` | Enable after fresh audit pass |
| `plugin enable <slug> --force` | Override failed audit (logged to `audit_log`) |
| `plugin disable <slug>` | Disable; files remain on disk |
| `plugin ignore <slug>` | Set `"ignored": true` in `plugin.json`, disable, skip audits (CLI only) |
| `plugin unignore <slug>` | Remove ignore flag |
| `plugin-audit <path\|slug>` | Static security scan (updates cache for installed slugs) |
| `plugin-audit <slug> --json` | Machine-readable audit report |

### Examples

On production the SQLite file is owned by the web server — run **mutating** commands as that user:

```bash
sudo -u apache php bin/latch plugin list
php bin/latch plugin install ./forum-stats-1.0.0.zip   # from Latch-plugins release
sudo -u apache php bin/latch plugin-audit forum-stats
sudo -u apache php bin/latch plugin enable forum-stats
sudo -u apache php bin/latch plugin disable example
sudo -u apache php bin/latch maintenance --clear-cache   # after enable/disable
```

Audit and failed enable work **without** a writable database — the scanner runs **before** any DB write:

```bash
# Audit only (any user with read access to plugins/ or docs/plugins/)
php bin/latch plugin-audit docs/plugins/badexample   # exit 1 — test fixture
# After copying to plugins/: php bin/latch plugin enable badexample  # blocked with report
```

### Install / remove workflow

```bash
php bin/latch plugin install /path/to/my-plugin          # local directory
php bin/latch plugin install ./releases/my-plugin.zip    # .zip (php-zip)
php bin/latch plugin install docs/plugins/example        # reference copy
php bin/latch plugin remove example --confirm
```

Install runs `plugin-audit` before completing. Critical findings roll back the copy. The plugin stays **disabled** until `plugin enable`.

### Enable workflow

1. `php bin/latch plugin install <dir|zip>` or ship files into `plugins/{slug}/` with valid `plugin.json`
2. `php bin/latch plugin-audit <slug>` — fix critical findings (install already audited once)
3. `php bin/latch plugin enable <slug>` (re-runs audit) or enable in **Admin → Plugins**
4. Clear cache if the site was already serving pages: `maintenance --clear-cache`

Catalog plugins ([Latch-plugins](https://github.com/YeOK/Latch-plugins)) install via `plugin install <dir|zip>` — **disabled** until you audit and enable. Core ships `md-import` (operator) under `plugins/` only. Reference copies in `docs/plugins/`: `example`, `badexample`, `warnexample`, `dbexample`.

### Security audit (`plugin-audit`)

Static scanner — **does not execute** plugin PHP. Walks the plugin tree; validates `plugin.json`; flags dangerous patterns.

**Caching:** For installed slugs under `plugins/`, results are written to `storage/cache/plugin-audits/{slug}.json`. Admin **Plugins** reuses the cache when files are unchanged. This command and `plugin enable` always **force a fresh scan**. `cron daily` refreshes all non-ignored plugins.

```bash
php bin/latch plugin-audit plugins/forum-stats
php bin/latch plugin-audit forum-stats --json
php bin/latch plugin audit forum-stats    # alias
```

| Exit code | Meaning |
|-----------|---------|
| `0` | No critical findings |
| `1` | Critical issues, invalid path, or **ignored** slug |

**Critical (blocks enable):** `eval`, shell functions, dynamic includes from request data, outbound HTTP without `permissions.network`, writes to `config/local.php` / `storage/database/`, path traversal, invalid manifest.

**Warning (review):** obfuscation patterns (`base64_decode`, …), `vendor/` without lock file, oversized files, suspicious markup in PHP (`markup_*` codes), suspicious JS in `assets/*.js` / `*.mjs` (`js_*` codes). Structural hook HTML (`<button>`, `<section>`) is not flagged.

```bash
php bin/latch plugin-audit docs/plugins/warnexample   # exit 0 — warnings only
php bin/latch plugin-audit docs/plugins/badexample    # exit 1 — critical findings
```

### Ignore workflow (CLI only)

```bash
php bin/latch plugin ignore md-import     # set "ignored": true, disable, skip future audits
php bin/latch plugin list --all           # list includes ignored
php bin/latch plugin unignore md-import   # restore discovery
```

Ignored plugins are omitted from admin **Plugins**, daily cron audits, and `plugin enable`. `plugin-audit <ignored-slug>` exits 1 with a hint to `unignore`.

`--force` on enable still writes `plugin.enable_forced` to `audit_log` with finding details. Admin UI does not offer force-enable.

Full rules: `docs/PLUGINS.md#security-audit`.

---

## reputation-recompute

Recompute member reputation ranks from source tables. Staff accounts are skipped/cleared. Use after changing weights/thresholds in admin settings, or to debug a single member.

```bash
php bin/latch reputation-recompute              # all members
php bin/latch reputation-recompute --user=42    # one user
```

On production (readonly DB for your login user):

```bash
sudo -u apache php bin/latch reputation-recompute
```

Scheduled runs: `cron hourly` (queue flush) and `cron daily` (full recompute) — see **cron** above.

---

## search-reindex

Rebuilds the FTS5 full-text index for topics, posts, and tags.

```bash
php bin/latch search-reindex
```

Run after bulk imports, spam purges, or if search results look stale.

---

## security-bootstrap

Generates `encryption_key` in `config/local.php` (if missing) and re-encrypts stored TOTP secrets. **Run once on production** before admins enrol 2FA.

```bash
php bin/latch security-bootstrap
```

---

## api-client

Manage OAuth clients for the REST API (`docs/API.md`).

```bash
# Confidential client (server-side app)
php bin/latch api-client create \
  --name="My integration" \
  --redirect=https://app.example/oauth/callback \
  --scopes=read,messages:read,messages:write

# Public client (mobile / SPA with PKCE)
php bin/latch api-client create \
  --name="Mobile app" \
  --public \
  --redirect=myapp://oauth

php bin/latch api-client list
php bin/latch api-client revoke --client-id=latch_…
```

| Option | Description |
|--------|-------------|
| `--name` | Application name (required for create) |
| `--redirect` | Redirect URI (repeatable; required for public clients) |
| `--public` | Public client — PKCE only, no `client_secret` |
| `--rate-limit` | Requests per minute (default 60) |
| `--user` | Admin username creating the client |

---

## benchmark

Times HTTP requests to home, a board, and a topic page.

```bash
php bin/latch benchmark --url=https://forum.example.com --iterations=10
```

---

## test-mail

Sends a test message using the configured mail transport (see `docs/EMAIL.md`).

```bash
php bin/latch test-mail --to=you@example.com
```

---

## mail process

Drains the optional notification mail queue (`mail_queue`). The same step runs inside `cron hourly` when **Queue notification emails** is enabled in Admin → Settings.

```bash
php bin/latch mail process
```

Stdout reports pending, sent, failed, and remaining counts. Auth emails (verify, reset, email change) are never queued.

---

## test-api

Runs live smoke tests against `/api/v1` and `/oauth/token`. Configure credentials in `tests/api/config.local.php` (copy from `config.example.php`).

### `test-api-messages`

Interactive harness for **user-delegated** OAuth and `/api/v1/messages/*`.

```bash
# 1. Create OAuth client on the server (once)
~/Documents/latch/scripts/setup-api-test-client.sh

# 2. Local config
cp tests/api/config.example.php tests/api/config.local.php
# Edit: client_id, client_secret, optional message_recipient

# 3. Browser login + PKCE (paste redirect URL or code when prompted)
php bin/latch test-api-messages authorize

# 4. Run smoke tests (uses cached token in tests/api/user-token.local.json)
php bin/latch test-api-messages run
```

```bash
php bin/latch test-api --url=https://forum.example.com
```

---

## post-announcements

Post Software Updates or changelog entries from a JSON file (idempotent HTML comment markers).

```bash
php bin/latch post-announcements \
  --topic=4 \
  --user=admin \
  --file=data/changelog-announcements.json
```

Use `--dry-run` to preview without writing.

---

## purge-users

Delete member accounts that have never posted (spam cleanup). Founder (user id 1) is never deleted. Also removes dependent rows (email verification tokens, password resets, OAuth tokens, DM threads, warnings, etc.) before deleting the user.

```bash
php bin/latch purge-users --ids=4,5,6
php bin/latch purge-users --ids=4,5,6 --dry-run
```

---

## test

Phase 5 release gates. Requires `php-xml` for PHPUnit; `php-curl` for live HTTP harnesses. Full detail: [TESTING.md](TESTING.md).

```bash
php bin/latch test                 # full suite (~260 tests, Latch testsuite)
php bin/latch test --smoke         # operator gate
php bin/latch test --security      # security gate

# Live HTTP probes (staging or production)
php bin/latch test --smoke --url=https://forum.example.com
php bin/latch test --security --url=https://forum.example.com
```

| Command | PHPUnit | CLI extras | HTTP (with `--url` or `tests/smoke/config.local.php`) |
|---------|---------|------------|------------------------------------------------------|
| `test` | Full `Latch` suite | — | — |
| `test --smoke` | `smoke` suite (migrations, restore, plugins, PostFormatter, cron, …) | `db-check`, `audit` | Web smoke + API harness if `tests/api/config.local.php` exists |
| `test --security` | `security` suite (CSRF, OIDC, ACL, spam, plugin audit, SSRF, …) | `audit` | Read-only security probes |

Without `php-xml`, `test --smoke` falls back to a built-in SQLite integrity check plus `db-check` and `audit`.

Config templates:

- `tests/smoke/config.example.php` → `config.local.php` — base URL and optional member credentials for mutating smoke
- `tests/api/config.example.php` → `config.local.php` — OAuth client for API harness

Equivalent direct PHPUnit:

```bash
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Latch
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite smoke
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite security
```

### Targeted test commands

| Command | What it runs |
|---------|----------------|
| `test-rss` | PHPUnit RSS tests + live feed validation (read-only DB or HTTP fallback) |
| `test-profiles` | Public profile repository tests |
| `test-spam` | Honeypot, link limits, approval queue tests |
| `test-webhooks` | Webhook repository tests |
| `test-api` | HTTP smoke tests in `tests/api/` only |
| `test-api-messages` | User-delegated OAuth + Messages API (see above) |

---

## import phpbb

Migrate **phpBB 3.3.x** (MySQL/MariaDB) into Latch via a portable JSON bundle. BBCode mapping: [MARKUP.md](MARKUP.md) § Imports.

**v1 constraints:** empty forum only (no existing topics/posts); phpBB passwords are **not** ported — users must reset via email.

### Export (once, from phpBB server)

Requires `php-pdo_mysql`:

```bash
php bin/latch import phpbb --export \
  --from-mysql='mysqli://user:pass@127.0.0.1/phpbb' \
  --out=/tmp/forum-bundle.json
```

### Import (repeatable)

```bash
php bin/latch lock on --message="Forum import"
php bin/latch backup

php bin/latch import phpbb --bundle=/tmp/forum-bundle.json --dry-run
php bin/latch import phpbb --bundle=/tmp/forum-bundle.json --confirm
php bin/latch search-reindex
php bin/latch lock off
```

| Flag | Purpose |
|------|---------|
| `--bundle=PATH` | JSON bundle to import |
| `--dry-run` | Count entities and BBCode warnings — no writes |
| `--confirm` | Write to database |
| `--json` | Machine-readable report |
| `--prefix=phpbb_` | Table prefix for `--export` (default `phpbb_`) |

Fixture bundles for tests: `scripts/fixtures/phpbb/minimal-bundle.json`, `edge-case-bundle.json`.

Custom BBCode strategies: `config/import-phpbb-bbcodes.php`.

---

## Operator scripts

| Script | Purpose |
|--------|---------|
| `scripts/install-cron.sh` | Install hourly/daily/weekly crontab for `apache` (**run as root**: `sudo bash scripts/install-cron.sh`); creates `storage/logs/cron.log` |
| `scripts/verify-cron.sh` | Check crontab, `cron.log`, and `maintenance_runs` / `last_cron_daily_at` |
| `scripts/migrate-latch-db.sh` | Copy DB to writable temp path, migrate, rsync back (when `latch.sqlite` is not writable) |
| `scripts/post-documentation.php` | Post `docs/*.md` to Documentation board topics (one topic per doc; idempotent markers) |
| `scripts/post-forum-updates.sh` | Post changelog, docs, and News topics (`announcements`, `documentation`, `news`, `all`) |

---

## Typical operator workflow

```bash
# After deploy (production)
bash scripts/migrate-latch-db.sh    # or: sudo -u apache php bin/latch migrate
php bin/latch maintenance

# First deploy / cron missing
bash scripts/install-cron.sh
tail -f storage/logs/cron.log

# Before tagging a release
php bin/latch doctor
php bin/latch test
php bin/latch test --smoke
php bin/latch test --security

# Weekly
php bin/latch backup
php bin/latch audit

# After reputation setting changes
sudo -u apache php bin/latch reputation-recompute

# After spam wave
php bin/latch purge-users --ids=… --dry-run
php bin/latch search-reindex

# Refresh forum documentation posts
php scripts/post-documentation.php data/documentation-posts.json
```

See also: `docs/TESTING.md`, `docs/INSTALL.md`, `docs/API.md`, `docs/EMAIL.md`, `docs/SECURITY.md`.