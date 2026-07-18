# Installing Latch on Fedora (COPR RPM)

Recommended for **Fedora/RHEL-like** hosts: `dnf` installs PHP, Apache, and extensions; the RPM lays out FHS paths; upgrades are `dnf upgrade latch`.

On Fedora/RHEL production hosts, upgrade with `sudo dnf upgrade latch`. Tarball installs use the same safety sequence in [UPGRADE.md](UPGRADE.md). Development and testing stay on your dev machine (optional staging VM for release gates).

## Enable COPR

```bash
sudo dnf copr enable yeok/latch
sudo dnf install latch
```

## First-time setup

```bash
sudo latch-setup \
  --url=https://forum.example.com \
  --name="My Forum" \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-pass='change-me-now'

sudo systemctl enable --now httpd php-fpm
sudo systemctl enable --now latch-cron-hourly.timer latch-cron-daily.timer latch-cron-weekly.timer
```

The RPM installs a **fail2ban** `latch-login` jail (watches `/var/lib/latch/storage/logs/security.log` for `login_fail` events with real client IPs). `dnf install latch` pulls in fail2ban via a weak dependency when recommends are enabled. Verify:

```bash
sudo fail2ban-client status latch-login
```

If you customized Apache log paths, edit `/etc/fail2ban/jail.d/latch-login.local` to match.

**Troubleshooting** — if `fail2ban-client status latch-login` shows `Total failed: 0` while `security.log` has `login_fail` events:

1. **Jail log path** — default is `security.log`, not Apache access log:
   ```bash
   sudo grep logpath /etc/fail2ban/jail.d/latch-login.local
   sudo fail2ban-regex /var/lib/latch/storage/logs/security.log /etc/fail2ban/filter.d/latch-login.conf
   ```
   Expect non-zero `Failregex` hits on `"event":"login_fail"` lines.

2. **Access log still shows `::1`** — normal behind a local reverse proxy; fail2ban uses `security.log` because Latch already resolves `CF-Connecting-IP`. Optional: `/etc/httpd/conf.d/latch-remoteip.conf` for correlating Apache access logs in Admin → Logs.

Edit the vhost `ServerName` if needed:

```bash
sudo vi /etc/httpd/conf.d/latch.conf
sudo systemctl reload httpd
```

### Configure secrets safely (recommended)

Secrets stay in `/etc/latch/local.php` (not the admin UI). Prefer the CLI walkthrough over hand-editing:

```bash
sudo latch configure           # interactive: site URL, Turnstile, mail, OIDC, plugin keys
sudo latch configure --show    # masked status of keys
sudo latch configure --section=turnstile,mail
```

Cloudflare (Free plan, Tunnel, Turnstile): [CLOUDFLARE.md](CLOUDFLARE.md).

### Paths (RPM layout)

| Path | Purpose |
|------|---------|
| `/usr/share/latch/source/` | Application code (replaced on upgrade) |
| `/var/lib/latch/storage/` | Runtime state (**preserved** on upgrade) |
| `/var/lib/latch/storage/database/latch.sqlite` | Forum database |
| `/var/lib/latch/storage/plugins/` | Plugin DBs + settings (`plugin.sqlite`, `settings.json`) |
| `/var/lib/latch/storage/backups/` | Split backup archives (`latch-backup-*.tar.gz`) |
| `/etc/latch/local.php` | Secrets and site URL (**preserved**, `config(noreplace)`) |
| `/usr/bin/latch` | CLI wrapper (runs as `apache` when needed) |

Symlinks wire `source/config/local.php` → `/etc/latch/local.php` and `source/storage` → `/var/lib/latch/storage`.

`latch-setup` creates `/etc/latch/local.php` with a random `security.encryption_key` before `bin/latch install`. Fresh tarball installs do the same via `install`; if you copied an old `local.php` without a key, run `sudo latch security-bootstrap`. If enrolled 2FA stops accepting codes after a key mismatch, see [CLI.md — totp](CLI.md#totp) (`sudo latch totp reset admin --confirm` as last resort).

## Backups and restore (RPM)

Always use **`sudo latch`** so files under `/var/lib/latch/storage/` are owned by `apache` (the web user). Do not run `php bin/latch backup` as root unless you then `chown` the archive.

### What is backed up

Each run writes one outer archive under `/var/lib/latch/storage/backups/`:

```text
latch-backup-YYYYMMDD-HHMMSS-<id>.tar.gz
├── core.tar.gz       # latch.sqlite + /etc/latch/local.php (via symlink path)
└── plugins.tar.gz    # storage/plugins/* (WAL-safe plugin.sqlite + settings)
```

| Member | Contents (Fedora paths) |
|--------|-------------------------|
| `core.tar.gz` | Forum DB + secrets file |
| `plugins.tar.gz` | Everything under `/var/lib/latch/storage/plugins/` |

Filenames include a random suffix so two backups in the same second never overwrite each other. Legacy flat archives (pre-split: only `storage/database/latch.sqlite`) still restore as **core**.

Full CLI detail: [CLI.md — backup](CLI.md#backup) and [CLI.md — restore](CLI.md#restore).

### Daily / ad-hoc backup

```bash
sudo latch backup                 # core + plugins (recommended)
sudo latch backup --core-only     # forum DB + local.php only
sudo latch backup --plugins-only  # plugin storage only
sudo latch restore list           # name, size, format, parts=[core, plugins]
```

Copy archives **off the server** (rsync, object storage, another host). A backup that only lives next to the DB is not disaster recovery.

```bash
# Example: off-site copy of the latest archive
ls -lt /var/lib/latch/storage/backups/latch-backup-*.tar.gz | head -3
sudo rsync -a /var/lib/latch/storage/backups/ backup-host:/backups/forum.example.com/
```

`dnf upgrade latch` already runs a pre-upgrade backup via `%posttrans` (see [Upgrades](#upgrades)). Still keep independent off-site copies.

### Full restore (same host)

```bash
sudo latch lock on
sudo latch restore list
sudo latch restore --latest --with-config   # or --name=latch-backup-….tar.gz
sudo latch db-check
sudo latch search-reindex                   # if search looks stale
sudo latch lock off
```

**`--with-config`** rewrites `/etc/latch/local.php` from the core archive (`encryption_key`, site URL, OIDC secrets, Turnstile keys). Skip it only when you intentionally keep the current secrets file.

Mail transport config (msmtp system files) is **not** in backups — reconfigure separately. See [EMAIL.md](EMAIL.md).

### Bad plugin recovery (keep forum, drop plugin state)

If a catalog plugin (or its SQLite/settings) is breaking the site:

```bash
sudo latch lock on
sudo latch restore --latest --core-only     # forum DB only; does NOT touch plugins/
sudo latch plugin disable bad-slug          # or: plugin remove bad-slug --confirm
# Optional: wipe that plugin's storage only
#   sudo rm -rf /var/lib/latch/storage/plugins/bad-slug
sudo latch lock off
```

To put plugin data back from an older good archive without touching the forum DB:

```bash
sudo latch lock on
sudo latch restore --name=latch-backup-….tar.gz --plugins-only
sudo latch lock off
```

### Disaster recovery (clean host)

```bash
sudo dnf copr enable yeok/latch
sudo dnf install latch httpd php-fpm

# Copy your off-site archive onto the server:
sudo install -d -o apache -g apache /var/lib/latch/storage/backups
sudo cp latch-backup-YYYYMMDD-HHMMSS-*.tar.gz /var/lib/latch/storage/backups/
sudo chown apache:apache /var/lib/latch/storage/backups/latch-backup-*.tar.gz

sudo latch lock on
sudo latch restore --latest --with-config   # restores core + plugins when both present
sudo latch db-check
sudo latch search-reindex
sudo latch doctor
sudo latch lock off

sudo systemctl enable --now httpd php-fpm
sudo systemctl enable --now latch-cron-hourly.timer latch-cron-daily.timer latch-cron-weekly.timer
```

After restore, re-run **`sudo latch configure --show`** (or `configure`) if you need to verify Turnstile/mail without printing secrets. Plugin code itself is not in the backup — reinstall from **Admin → Plugins → Catalog** or `sudo latch plugin install …` if `/usr/share/latch/source/plugins/` is empty for a slug.

## Upgrades

When a new release is tagged, COPR builds a new RPM. On the server:

```bash
sudo dnf upgrade latch
```

The RPM **`%posttrans`** hook runs the same sequence as `scripts/update.sh`:

1. Site lock  
2. WAL-safe **split** backup (core + plugins → `/var/lib/latch/storage/backups/`)  
3. `bin/latch update` (migrate, db-check, cron, audit)  
4. Unlock  

Daily cron includes **plugin security audits** (cached under `storage/cache/plugin-audits/`). Optional SQLite PRAGMA tuning: [INSTALL.md](INSTALL.md#sqlite-tuning-optional).

If upgrade fails, the site stays locked. Roll back:

```bash
sudo latch lock status
sudo latch restore list
sudo latch restore --latest --with-config   # full; or --core-only if plugins look wrong
sudo latch db-check
sudo latch lock off
```

See [UPGRADE.md](UPGRADE.md) for general (tarball) wording.

## Migrating from `/var/www/latch` (tarball/rsync)

One-time cutover for an existing install:

1. **Backup:** `cd /var/www/latch/source && php bin/latch backup` (or copy `storage/backups/`). Prefer a fresh split backup so plugin DBs travel with core.
2. Install RPM on the same host (or staging first).
3. Stop traffic (site lock or maintenance page).
4. Copy state:
   - `storage/` → `/var/lib/latch/storage/` (includes `database/`, `plugins/`, `backups/`)
   - `config/local.php` → `/etc/latch/local.php`
5. Fix ownership: `sudo chown -R apache:apache /var/lib/latch/storage`
6. `sudo chmod 640 /etc/latch/local.php && sudo chown root:apache /etc/latch/local.php`
7. Point Apache at `/usr/share/latch/source/public` (RPM vhost).
8. `sudo latch doctor` (or `sudo -u apache php /usr/share/latch/source/bin/latch doctor`)
9. Retire manual rsync deploy; use `dnf upgrade` for updates.

## Operator commands

Use **`sudo latch`** for commands that write under `/var/lib/latch/storage/` (database, plugin settings, audit cache, **backups**). The wrapper runs PHP as `apache`; plain `php bin/latch` as root or as your login user often causes permission errors on `plugin enable` and plugin settings saves.

```bash
sudo latch doctor
sudo latch backup
sudo latch backup --core-only
sudo latch restore list
sudo latch restore --latest --core-only   # bad-plugin escape hatch
sudo latch lock on
sudo latch plugin install ./forum-stats-1.0.0.zip
sudo latch plugin enable forum-stats
sudo journalctl -u latch-cron-daily.service
```

If plugin enable or settings save still fails, fix storage ownership once:

```bash
sudo chown -R apache:apache /var/lib/latch/storage/plugins /var/lib/latch/storage/cache/plugin-audits
sudo bash /usr/share/latch/source/scripts/fix-latch-storage-perms.sh /usr/share/latch
```

Details: [PLUGINS.md — Production permissions](PLUGINS.md#production-permissions-rpm--apache).

See [CLI.md](CLI.md) for the full command reference.

## Server logs (admin viewer)

The COPR vhost (`packaging/latch-httpd.conf` → `/etc/httpd/conf.d/latch.conf`) writes:

| Log | Default path |
|-----|----------------|
| Access | `/var/log/httpd/latch-access.log` |
| Error | `/var/log/httpd/latch-error.log` |

fail2ban `latch-login` watches `security.log` for `login_fail` events (`packaging/fail2ban/latch-login.local` → `/var/lib/latch/storage/logs/security.log`). Apache access logs are optional for correlation in Admin → Logs (`latch-access.log`).

**Admin → Logs** always shows Latch-owned files. To tail Apache/PHP-FPM logs in the UI or CLI, enable server sources in `/etc/latch/local.php`:

```php
'logs' => [
    'server_logs_enabled' => true,
    'sources' => [
        [
            'id' => 'httpd.access',
            'label' => 'Apache access',
            'group' => 'Web server',
            'path' => '/var/log/httpd/latch-access.log',
            'format' => 'text',
        ],
        [
            'id' => 'httpd.error',
            'label' => 'Apache error',
            'group' => 'Web server',
            'path' => '/var/log/httpd/latch-error.log',
            'format' => 'text',
        ],
        [
            'id' => 'php-fpm.slow',
            'label' => 'PHP-FPM slowlog',
            'group' => 'PHP',
            'path' => '/var/log/php-fpm/www-slow.log',
            'format' => 'text',
        ],
    ],
],
```

PHP-FPM runs as `apache`. Default perms on `/var/log/httpd/latch-*.log` are typically root-owned, group `apache`, mode `0640` — **readable** by the viewer without extra steps.

Slowlog and other files may not be:

```bash
# Preferred — grant read without changing group membership
sudo setfacl -m u:apache:r /var/log/php-fpm/www-slow.log

# Verify
sudo latch logs list
sudo latch logs tail --source=httpd.access --lines=5
```

Unreadable sources show a **permission denied** badge in the admin UI and fail `sudo latch doctor` / `sudo latch audit` when `server_logs_enabled` is on.

**Debugging login abuse** — filter `latch.security` for `login_fail`, then correlate timestamps with `httpd.access` (`POST /login`). Same access log path fail2ban uses.

More: [SECURITY.md — Admin log viewer](SECURITY.md#admin-log-viewer), [CLI.md — logs](CLI.md#logs), `packaging/README.md`.

## Related docs

- [INSTALL.md](INSTALL.md) — generic install (tarball, git)  
- [UPGRADE.md](UPGRADE.md) — rollback and manual update sequence  
- [CLI.md](CLI.md#backup) — split backup / restore flags  
- [SECURITY.md](SECURITY.md#backups) — backup posture and corruption steps  
- [PLUGINS.md](PLUGINS.md) — plugin storage and catalog