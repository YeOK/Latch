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

## Release install (v0.4.x)

Download the latest **`latch-<version>.tar.gz`** and **`SHA256SUMS`** from **[GitHub Releases](https://github.com/YeOK/Latch/releases)** (example below uses `0.4.4.1`):

```bash
sha256sum -c SHA256SUMS
tar -xzf latch-0.4.4.1.tar.gz
cd latch-0.4.4.1-stage
bash scripts/install.sh --url=https://forum.example.com --name="My Forum"
```

(`install.sh` runs Composer, `bin/latch install`, `doctor`, and cron when invoked as root. Manual path: `cd source && composer install --no-dev && php bin/latch install …`.)

Or clone the public repo (`vendor/` is not in git — `install.sh` runs Composer from `composer.lock`):

```bash
git clone https://github.com/YeOK/Latch.git
cd Latch
bash scripts/install.sh --url=https://forum.example.com --name="My Forum"
```

Point the web server **only** at `public/`. Keep `storage/` and `config/local.php` private.

## Quick install (from source tree)

```bash
bash scripts/install.sh
# or manually: cd source && composer install --no-dev && php bin/latch install
```

The installer will:

1. Write `config/local.php` (site URL, name, and `security.encryption_key` for admin 2FA)
2. Create `storage/database/latch.sqlite` and apply migrations
3. Create an admin user
4. Seed a default **General** board
5. Run **security bootstrap** if `encryption_key` is still missing (e.g. resumed install with an old `local.php`)

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

1. **DNS & TLS** — point your domain at the origin. A reverse proxy (Cloudflare, Caddy, nginx) can terminate HTTPS; Apache/nginx on the host can listen on port 80 or behind the proxy. Cloudflare Free (proxy, Tunnel, Turnstile): [CLOUDFLARE.md](CLOUDFLARE.md).
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

3. **Config walkthrough** (optional, interactive) — after install, set Turnstile, mail, OIDC, and plugin secrets without pasting secrets into the browser:

```bash
php bin/latch configure
php bin/latch configure --show
```

Secrets remain in `config/local.php` only. See [CLOUDFLARE.md](CLOUDFLARE.md) and [CLI.md](CLI.md).

4. **Upgrades** — replace application files, then run `sudo bash scripts/update.sh` from the install root (see [UPGRADE.md](UPGRADE.md)). Existing `storage/database/latch.sqlite` and `config/local.php` are preserved.

Example Apache vhost: `packaging/latch-httpd.conf` (also installed as `/etc/httpd/conf.d/latch.conf` on COPR). Fedora/RHEL: [INSTALL-FEDORA.md](INSTALL-FEDORA.md).

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

## SQLite tuning (optional)

Latch applies WAL mode, foreign keys, and performance PRAGMAs on every connection. Defaults live in `config/default.php` under `database.sqlite`:

| Key | Default | Purpose |
|-----|---------|---------|
| `busy_timeout_ms` | `5000` | Wait on locked DB before `SQLITE_BUSY` |
| `cache_size_kib` | `8192` | Page cache (8 MiB); `0` leaves SQLite default |
| `mmap_size` | `0` | Memory-mapped reads (bytes); `0` = disabled |

Override in `config/local.php` only when you need to tune a busy site — installs and upgrades pick up defaults automatically. Example:

```php
'database' => [
    'sqlite' => [
        'busy_timeout_ms' => 10000,
        'cache_size_kib' => 32768,
        'mmap_size' => 268435456,
    ],
],
```

See also [PERFORMANCE.md](PERFORMANCE.md) (query hot paths, SQLite scale), [CDN.md](CDN.md) (Cloudflare cache rules), and [SECURITY.md](SECURITY.md) (WAL backups, permissions).

## Admin log viewer (optional)

Latch application logs are always visible in **Admin → Logs** and via CLI. To also tail **web server** logs from the admin panel (or `bin/latch logs`), add to `config/local.php`:

```php
'logs' => [
    // Master switch — built-in latch.* sources stay on without this.
    'server_logs_enabled' => true,

    // Optional extra roots (absolute paths only; max 5). Defaults already include
    // /var/log and {paths.storage}/logs — omit unless you use a custom layout.
    // 'allowed_roots' => ['/srv/latch/logs'],

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

Adjust paths for your stack (Debian `apache2`, nginx, custom vhost `ErrorLog`/`CustomLog`). PHP-FPM must be able to **read** each file — on Fedora, Apache access/error logs under `/var/log/httpd/` are usually group-readable; PHP-FPM slowlog often needs `setfacl` (see [INSTALL-FEDORA.md](INSTALL-FEDORA.md#server-logs-admin-viewer)).

Verify from the install tree:

```bash
php bin/latch logs list
php bin/latch logs tail --source=latch.security --lines=20
```

Details: [SECURITY.md — Admin log viewer](SECURITY.md#admin-log-viewer), [CLI.md — logs](CLI.md#logs).

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

On **Fedora/RPM** installs, `/usr/bin/latch` wraps the same commands as `apache` — prefer `sudo latch …` over `php bin/latch …` when installing or enabling plugins. See [INSTALL-FEDORA.md](INSTALL-FEDORA.md).

Plugin state (`storage/plugins/{slug}/settings.json`, `plugin.sqlite`) and audit cache (`storage/cache/plugin-audits/`) must also be owned by the web user. If `plugin enable` fails with **audit cache** or admin cannot save plugin settings, run `scripts/fix-latch-storage-perms.sh` or see [PLUGINS.md — Production permissions](PLUGINS.md#production-permissions-rpm--apache).

## After install

1. Sign in as admin at `/login`
2. Enable **two-factor authentication** on your admin account (Profile → Security) — requires `security.encryption_key` in `config/local.php` (set automatically by `install`; existing sites: `php bin/latch security-bootstrap`). If 2FA codes stop working after a key or config change, see [CLI.md — totp](CLI.md#totp).
3. Open `/admin` to manage users, boards, and settings
4. Post in your first board
5. Run `php bin/latch doctor` and `php bin/latch audit` before going live

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
php bin/latch security-bootstrap   # set encryption_key if missing (also runs at end of install)
php bin/latch audit          # security self-check (headers, permissions, debug leakage)
php bin/latch backup         # WAL-safe split tarball (core + plugins) to storage/backups/
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