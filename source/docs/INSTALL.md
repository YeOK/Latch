# Installing Latch

Latch is a self-hosted PHP forum. Only the `public/` directory should be exposed to the web.

## Requirements

- PHP 8.2+ with extensions: `pdo_sqlite`, `mbstring`, `json`, `session`

On Fedora/RHEL, install the CLI modules:

```bash
sudo dnf install -y php-pdo php-mbstring
```

(`php-pdo` provides both PDO and the SQLite driver.)
- Composer (or use the bundled `composer.phar`)
- Apache with `mod_rewrite`, or nginx with equivalent routing

## Release install (v0.3.0+)

Download from **[GitHub Releases](https://github.com/YeOK/Latch/releases)** (`latch-0.3.0.13.tar.gz` + `SHA256SUMS`):

```bash
sha256sum -c SHA256SUMS
tar -xzf latch-0.3.0.13.tar.gz
cd latch-0.3.0.13-stage/source
composer install --no-dev
php bin/latch install --url=https://forum.example.com --name="My Forum"
```

Or clone the public repo:

```bash
git clone https://github.com/YeOK/Latch.git
cd Latch/source
composer install --no-dev
php bin/latch install
```

Point the web server **only** at `public/`. Keep `storage/` and `config/local.php` private.

## Quick install (from source tree)

```bash
cd source
php composer.phar install --no-dev
php bin/latch install
```

The installer will:

1. Write `config/local.php` (site URL and name)
2. Create `storage/database/latch.sqlite` and apply migrations
3. Create an admin user
4. Seed a default **General** board

Non-interactive example:

```bash
php bin/latch install \
  --url=https://forum.example.com \
  --name="My Forum" \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-pass='change-me-now'
```

## Production deployment

Typical layout: install under `/var/www/latch` (or your vhost path), web root at `source/public`, database and logs in `storage/`.

1. **DNS & TLS** — point your domain at the origin. A reverse proxy (Cloudflare, Caddy, nginx) can terminate HTTPS; Apache/nginx on the host can listen on port 80 or behind the proxy.
2. **First deploy** — copy or extract the release tree to the server, then:

```bash
cd /var/www/latch/source
composer install --no-dev
php bin/latch install \
  --url=https://forum.example.com \
  --name="My Forum" \
  --admin-user=admin \
  --admin-email=admin@example.com
```

3. **Upgrades** — replace application files, then run `sudo bash scripts/update.sh` from the install root (see [UPGRADE.md](UPGRADE.md)). Existing `storage/database/latch.sqlite` and `config/local.php` are preserved.

A reference install runs at **[latch.network](https://latch.network)**. Example Apache vhost: `packaging/latch-httpd.conf` (also installed as `/etc/httpd/conf.d/latch.conf` on COPR). Fedora/RHEL: [INSTALL-FEDORA.md](INSTALL-FEDORA.md).

## Web server

### Apache

See `packaging/latch-httpd.conf` for a starter vhost. Key points:

- `DocumentRoot` must point to `source/public`
- `AllowOverride All` so `.htaccess` rewrite rules work
- `storage/`, `config/local.php`, and `vendor/` must not be web-accessible

### nginx

```nginx
root /var/www/latch/source/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php-fpm/www.sock;
}
```

## File permissions

`storage/` must be writable by the web server user (`apache` on Fedora). Production layouts use **2770** directories and **660** database files (group `apache`, no world access).

Add your deploy user to the `apache` group so you can run CLI commands without sudo:

```bash
sudo usermod -aG apache your-deploy-user
# then log out and back in, or: newgrp apache
```

Until you re-login, run maintenance as the web user:

```bash
sudo -u apache php bin/latch migrate
sudo -u apache php bin/latch backup
sudo -u apache php bin/latch maintenance
```

## After install

1. Sign in as admin at `/login`
2. Open `/admin` to manage users, boards, and settings
3. Post in your first board

## Migrations

After pulling updates:

```bash
php bin/latch migrate
```

If you get `attempt to write a readonly database`, the database directory is not writable by your user (common when files are owned by `apache`):

```bash
sudo -u apache php bin/latch migrate
```

Or use the helper (no write access to `storage/database/` required):

```bash
bash scripts/migrate-latch-db.sh
```

For a permanent fix from the install root:

```bash
sudo bash scripts/fix-latch-storage-perms.sh /var/www/latch
sudo usermod -aG apache your-deploy-user   # re-login afterward
```

## Email

Password reset and registration verification need outbound mail. See **[EMAIL.md](EMAIL.md)** for msmtp setup, admin settings, and `test-mail`.

Quick check after configuring:

```bash
sudo -u apache php bin/latch test-mail --to=admin@example.com
```

## Cron (required for production)

Latch needs scheduled maintenance to prune tokens, notifications, rate-limit buckets, and refresh reputation. **Cron does not clear guest page cache** — only `maintenance --clear-cache` or deploy does.

After install or upgrade:

```bash
sudo bash scripts/install-cron.sh
# Custom path: LATCH_ROOT=/var/www/latch/source WEB_USER=apache sudo -E bash scripts/install-cron.sh
```

This installs three jobs for the `apache` user (hourly, daily, weekly). Logs append to `source/storage/logs/cron.log`.

Verify manually:

```bash
sudo -u apache php bin/latch cron daily
```

On Fedora COPR, systemd timers replace cron — see [INSTALL-FEDORA.md](INSTALL-FEDORA.md).

**Docker:** run the same commands in a sidecar cron container or the host crontab pointing at the mounted `source/` volume.

## Upgrading

After a new release lands (tarball, Composer, or Docker image):

```bash
sudo bash scripts/update.sh
# or manually: lock → backup → replace files → bin/latch update
```

See **[UPGRADE.md](UPGRADE.md)** for rollback, `db-check`, and `restore --latest`.

## Operations (Phase 1.5+)

```bash
php bin/latch audit          # security self-check (headers, permissions, debug leakage)
php bin/latch backup         # WAL-safe tarball to storage/backups/
php bin/latch db-check       # SQLite integrity after migrate/restore
php bin/latch restore list   # list backups; restore --latest when locked
php bin/latch cron daily     # scheduled DB prunes (also run by crontab)
php bin/latch maintenance --clear-cache   # after deploy if needed
php bin/latch maintenance --vacuum
php bin/latch benchmark --url=http://localhost
php bin/latch test-mail --to=you@example.com
```

Health endpoint: `GET /health` returns JSON `{status, version, cache_enabled}`.

## PHP-FPM production checklist

- Enable **OPcache** in `php.ini` (`opcache.enable=1`, adequate `memory_consumption`)
- Set `opcache.validate_timestamps=0` in production and reload PHP-FPM after deploys
- Install cron via `scripts/install-cron.sh` (see **Cron** above)
- Keep `storage/` outside the web root; verify with `php bin/latch audit`

## Security notes

- Failed logins return HTTP 200 (for fail2ban integration); successful logins return 302
- CSRF tokens protect all mutating forms
- Login attempts are rate-limited per IP
- Post creation is rate-limited per user