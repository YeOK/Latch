# Upgrading Latch

One-page operator runbook for self-hosted forums. **Private deploy** (`sync-latch.sh` on latch.network) and **public install** (`scripts/update.sh` or release tarball) follow the same safety sequence.

## Quick path (recommended)

On the server, after new files land:

```bash
cd /var/www/latch
sudo bash scripts/update.sh
```

This runs:

1. **Lock** — `php bin/latch lock on`
2. **Backup** — WAL-safe tarball in `storage/backups/`
3. **Composer** — `composer install --no-dev` when available
4. **Update** — `php bin/latch update --skip-lock --skip-backup --assume-files-ready`
   - migrate (privilege-aware)
   - **db-check** (mandatory)
   - cron daily
   - audit
   - unlock

Add `--clear-cache` to the shell script invocation to purge guest page cache at the end.

## Manual sequence

Use when you deploy files yourself (rsync, tarball, Docker overlay):

```bash
cd /var/www/latch/source

# 1. Quiesce traffic
php bin/latch lock on --message="Latch update"

# 2. Backup (WAL-safe)
php bin/latch backup

# 3. Replace core files (preserve storage/, config/local.php, plugins/)
#    app/ bin/ public/ database/migrations/ vendor/ themes/ lang/

# 4. Migrate + verify + housekeeping
sudo -u apache php bin/latch update --skip-lock --skip-backup --assume-files-ready

# Or step by step:
sudo -u apache php bin/latch migrate
php bin/latch db-check
sudo -u apache php bin/latch cron daily
php bin/latch audit
php bin/latch maintenance --clear-cache   # after theme/asset changes
php bin/latch lock off
```

Run migrate and cron as the web server user when SQLite is `apache`-owned (`660 apache:apache`).

## Rollback

If **migrate** or **db-check** fails during `bin/latch update`, the site stays locked. Do not unlock until the database is healthy.

```bash
php bin/latch lock status          # should be on
php bin/latch restore list         # pick a backup
php bin/latch restore --latest     # or --name=latch-backup-YYYYMMDD-HHMMSS.tar.gz
php bin/latch db-check             # must pass before unlock
php bin/latch lock off
```

Restore **requires site lock** by default. Break-glass only:

```bash
php bin/latch restore --latest --force   # dangerous — logs restore.forced
```

### If `restore --latest` fails

1. Pre-restore snapshot: `storage/backups/.pre-restore-latest.sqlite`
2. Publish manually: `php ../scripts/sqlite-backup.php .pre-restore-latest.sqlite storage/database/latch.sqlite`
3. `php bin/latch db-check`

## Manual recovery (no valid backup)

Last resort when `db-check` fails and backups are unusable:

```bash
php bin/latch lock on
mv storage/database/latch.sqlite storage/database/latch.sqlite.corrupt
# If sqlite3 CLI is available:
sqlite3 storage/database/latch.sqlite.corrupt ".recover" | sqlite3 storage/database/latch-recovered.sqlite
php bin/latch db-check --db=storage/database/latch-recovered.sqlite
php ../scripts/sqlite-backup.php storage/database/latch-recovered.sqlite storage/database/latch.sqlite
php bin/latch db-check
php bin/latch lock off
```

Data loss is possible. Prefer nightly `bin/latch backup` and off-site copies.

## What to preserve

| Keep | Replace on upgrade |
|------|-------------------|
| `storage/database/latch.sqlite` | `app/`, `bin/`, `public/` |
| `config/local.php` | `database/migrations/` |
| `storage/plugins/` (enabled plugins) | `vendor/` (re-run Composer) |
| `storage/backups/` | Core `themes/`, `lang/` |

## If `update.sh` stops at audit

The site stays **locked** until audit passes and you unlock. Common on production:

```bash
# DB must not be world-readable (expect 660 apache:apache)
sudo chmod 660 /var/www/latch/source/storage/database/latch.sqlite
sudo chown apache:apache /var/www/latch/source/storage/database/latch.sqlite
sudo -u apache php bin/latch audit
sudo -u apache php bin/latch lock off
```

Or run `sudo bash scripts/fix-latch-storage-perms.sh` from the repo root.

## Exit codes (`bin/latch update`)

| Code | Step failed | Action |
|------|-------------|--------|
| 12 | migrate | `restore --latest` |
| 13 | db-check | `restore --latest` |
| 17 | audit | Fix permissions/secrets; stay locked until resolved |
| 15 | unlock | `sudo -u apache php bin/latch lock off` |

See [CLI.md](CLI.md) for full `db-check`, `restore`, and `update` options.

## Private operator note (latch.network)

`scripts/sync-latch.sh` publishes code from your dev machine; run `scripts/update.sh` on the server after publish. Site lock + backup + db-check are not optional for production SQLite under write load.