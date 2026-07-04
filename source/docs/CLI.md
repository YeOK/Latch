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
| `db-check` | SQLite `integrity_check` / `quick_check` + `foreign_key_check` |
| `restore` | List or restore from `storage/backups/` (lock required) |
| `update` | Lock → backup → migrate → db-check → cron → audit → cache → unlock |
| `cron hourly` | Rate-limit prunes, reputation queue flush |
| `cron daily` | DB prunes, notifications, full reputation recompute (no cache purge) |
| `cron weekly` | ANALYZE, DM/topic_reads cleanup; `--audit` prunes audit_log |
| `reputation-recompute` | Manual full or per-user rank recompute |
| `plugin list` | List plugins and enabled state |
| `plugin audit <path\|slug>` | Security scan (alias for `plugin-audit`) |
| `plugin enable <slug>` | Enable after audit pass (`--force` to override) |
| `plugin disable <slug>` | Disable a plugin |
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

**`attempt to write a readonly database`** — SQLite needs **write permission on `storage/database/`** (for WAL/journal files), not just on `latch.sqlite`. On production the directory is often `750` and owned by `apache`:

```bash
sudo -u apache php bin/latch migrate
# or:
bash scripts/migrate-latch-db.sh
```

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
| `hourly` | Every hour at `:00` | Flushes `reputation_queue` (debounced post/vote/warn events) |
| `daily` | `03:15` | Full `recomputeAll()` for all members |
| `weekly` | Sunday `04:30` | `ANALYZE`, DM/topic_reads prunes, **`foreign_key_violations`** count in log (0 = healthy) |

Stdout/stderr from crontab append to **`storage/logs/cron.log`**. After first deploy, confirm the log updates on the next hourly and daily run.

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

Retention defaults (override via `settings` table or admin UI): read notifications **90d**, login attempts **14d**, notification cap **500**/user, audit log **365d**.

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

Customize Latch **without editing core** — see `docs/PLUGINS.md`. Installed plugins live in `plugins/{slug}/`. Enabled slugs are stored in `settings.enabled_plugins` (JSON). **Admin UI:** `/admin/plugins` (audit status, enable/disable).

### Quick reference

| Command | Purpose |
|---------|---------|
| `plugin list` | Discovered plugins, version, enabled/disabled, Latch compatibility |
| `plugin audit <path\|slug>` | Run security scan (same as `plugin-audit`) |
| `plugin enable <slug>` | Enable after audit pass |
| `plugin enable <slug> --force` | Override failed audit (logged to `audit_log`) |
| `plugin disable <slug>` | Disable; files remain on disk |
| `plugin-audit <path\|slug>` | Static security scan |
| `plugin-audit <slug> --json` | Machine-readable audit report |

### Examples

On production the SQLite file is owned by the web server — run **mutating** commands as that user:

```bash
sudo -u apache php bin/latch plugin list
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

### Enable workflow

1. Copy or ship plugin into `plugins/{slug}/` with valid `plugin.json`
2. `php bin/latch plugin-audit <slug>` — fix critical findings
3. `php bin/latch plugin enable <slug>` (re-runs audit) or enable in **Admin → Plugins**
4. Clear cache if the site was already serving pages: `maintenance --clear-cache`

Active bundled plugins: `forum-stats` (home page stats bar), `image-upload` (R2 compose upload). Reference copies in `docs/plugins/`: `example`, `badexample` (critical audit test), `warnexample` (warning audit test).

### Security audit (`plugin-audit`)

Static scanner — **does not execute** plugin PHP. Walks the plugin tree; validates `plugin.json`; flags dangerous patterns.

```bash
php bin/latch plugin-audit plugins/forum-stats
php bin/latch plugin-audit forum-stats --json
php bin/latch plugin audit forum-stats    # alias
```

| Exit code | Meaning |
|-----------|---------|
| `0` | No critical findings |
| `1` | Critical issues or invalid path |

**Critical (blocks enable):** `eval`, shell functions, dynamic includes from request data, outbound HTTP without `permissions.network`, writes to `config/local.php` / `storage/database/`, path traversal, invalid manifest.

**Warning (review):** obfuscation patterns (`base64_decode`, …), `vendor/` without lock file, oversized files, suspicious markup in PHP (`markup_*` codes), suspicious JS in `assets/*.js` / `*.mjs` (`js_*` codes). Structural hook HTML (`<button>`, `<section>`) is not flagged.

```bash
php bin/latch plugin-audit docs/plugins/warnexample   # exit 0 — warnings only
php bin/latch plugin-audit docs/plugins/badexample    # exit 1 — critical findings
```

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
php bin/latch benchmark --url=https://latch.network --iterations=10
```

---

## test-mail

Sends a test message using the configured mail transport (see `docs/EMAIL.md`).

```bash
php bin/latch test-mail --to=you@example.com
```

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
php bin/latch test-api --url=https://latch.network
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

## Test commands

| Command | What it runs |
|---------|----------------|
| `test-rss` | PHPUnit RSS tests + live feed validation (read-only DB or HTTP fallback) |
| `test-profiles` | Public profile repository tests |
| `test-spam` | Honeypot, link limits, approval queue tests |
| `test-api` | HTTP smoke tests in `tests/api/` |

For full PHPUnit suite:

```bash
cd source && ./vendor/bin/phpunit
```

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

See also: `docs/INSTALL.md`, `docs/API.md`, `docs/EMAIL.md`, `docs/SECURITY.md`.