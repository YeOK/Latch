# Installing Latch on Fedora (COPR RPM)

Recommended for **Fedora/RHEL-like** hosts: `dnf` installs PHP, Apache, and extensions; the RPM lays out FHS paths; upgrades are `dnf upgrade latch`.

**Production (latch.network)** runs the COPR RPM (`dnf upgrade latch`). Tarball installs use the same safety sequence in [UPGRADE.md](UPGRADE.md). Development and testing stay on your dev machine (optional staging VM for release gates).

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

The RPM installs a **fail2ban** `latch-login` jail (watches `/var/log/httpd/latch-access.log` for failed `POST /login`). `dnf install latch` pulls in fail2ban via a weak dependency when recommends are enabled. Verify:

```bash
sudo fail2ban-client status latch-login
```

If you customized Apache log paths, edit `/etc/fail2ban/jail.d/latch-login.local` to match.

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

`latch-setup` creates `/etc/latch/local.php` with a random `security.encryption_key` before `bin/latch install`. Fresh tarball installs do the same via `install`; if you copied an old `local.php` without a key, run `sudo -u apache latch security-bootstrap`.

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
9. Retire rsync deploy to prod; use `dnf upgrade` for updates.

## Operator commands

```bash
sudo latch doctor
sudo latch backup
sudo latch restore list
sudo latch lock on
sudo journalctl -u latch-cron-daily.service
```

See [CLI.md](CLI.md) for the full command reference.

## Related docs

- [INSTALL.md](INSTALL.md) — generic install (tarball, git)  
- [UPGRADE.md](UPGRADE.md) — rollback and manual update sequence