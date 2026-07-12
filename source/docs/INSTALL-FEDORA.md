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

### Paths (RPM layout)

| Path | Purpose |
|------|---------|
| `/usr/share/latch/source/` | Application code (replaced on upgrade) |
| `/var/lib/latch/storage/` | SQLite DB, backups, logs, cache (**preserved**) |
| `/etc/latch/local.php` | Secrets and site URL (**preserved**, `config(noreplace)`) |
| `/usr/bin/latch` | CLI wrapper (runs as `apache` when needed) |

Symlinks wire `source/config/local.php` → `/etc/latch/local.php` and `source/storage` → `/var/lib/latch/storage`.

`latch-setup` creates `/etc/latch/local.php` with a random `security.encryption_key` before `bin/latch install`. Fresh tarball installs do the same via `install`; if you copied an old `local.php` without a key, run `sudo -u apache latch security-bootstrap`. If enrolled 2FA stops accepting codes after a key mismatch, see [CLI.md — totp](CLI.md#totp) (`sudo latch totp reset admin --confirm` as last resort).

## Upgrades

When a new release is tagged, COPR builds a new RPM. On the server:

```bash
sudo dnf upgrade latch
```

The RPM **`%posttrans`** hook runs the same sequence as `scripts/update.sh`:

1. Site lock  
2. WAL-safe backup  
3. `bin/latch update` (migrate, db-check, cron, audit)  
4. Unlock  

Daily cron includes **plugin security audits** (cached under `storage/cache/plugin-audits/`). Optional SQLite PRAGMA tuning: [INSTALL.md](INSTALL.md#sqlite-tuning-optional).

If upgrade fails, the site stays locked. Roll back with [UPGRADE.md](UPGRADE.md) (`restore --latest --with-config`).

## Disaster recovery (clean host)

```bash
sudo dnf copr enable yeok/latch
sudo dnf install latch httpd php-fpm

# Copy your backup archive onto the server, e.g.:
sudo install -d -o apache -g apache /var/lib/latch/storage/backups
sudo cp latch-backup-YYYYMMDD-HHMMSS.tar.gz /var/lib/latch/storage/backups/

sudo -u apache latch lock on
sudo -u apache latch restore --latest --with-config
sudo -u apache latch db-check
sudo -u apache latch search-reindex
sudo -u apache latch lock off

sudo systemctl enable --now httpd latch-cron-hourly.timer latch-cron-daily.timer latch-cron-weekly.timer
```

**Use `--with-config`** so `encryption_key`, OIDC secrets, and site URL are restored with the database.

Reconfigure mail separately (`deploy/msmtp.conf` is not in backups). See [EMAIL.md](EMAIL.md).

## Migrating from `/var/www/latch` (tarball/rsync)

One-time cutover for an existing install:

1. **Backup:** `php bin/latch backup` (or copy `storage/backups/`).
2. Install RPM on the same host (or staging first).
3. Stop traffic (site lock or maintenance page).
4. Copy state:
   - `storage/` → `/var/lib/latch/storage/`
   - `config/local.php` → `/etc/latch/local.php`
5. Fix ownership: `sudo chown -R apache:apache /var/lib/latch/storage`
6. `sudo chmod 640 /etc/latch/local.php && sudo chown root:apache /etc/latch/local.php`
7. Point Apache at `/usr/share/latch/source/public` (RPM vhost).
8. `sudo latch` or `sudo -u apache php /usr/share/latch/source/bin/latch doctor`
9. Retire manual rsync deploy; use `dnf upgrade` for updates.

## Operator commands

Use **`sudo latch`** for commands that write under `/var/lib/latch/storage/` (database, plugin settings, audit cache). The wrapper runs PHP as `apache`; plain `php bin/latch` as root or as your login user often causes permission errors on `plugin enable` and plugin settings saves.

```bash
sudo latch doctor
sudo latch backup
sudo latch restore list
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

fail2ban `latch-login` watches the access log (`packaging/fail2ban/latch-login.local`). Application auth events also land in `storage/logs/security.log` (symlinked to `/var/lib/latch/storage/logs/`).

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