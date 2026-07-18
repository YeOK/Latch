# Latch RPM packaging (Fedora / COPR)

| File | Purpose |
|------|---------|
| `latch.spec` | RPM spec — bump `Version:` with repo `VERSION` on each release |
| `latch-cli` | `/usr/bin/latch` wrapper |
| `latch-setup` | First-time `bin/latch install` after `dnf install latch` (then tip: `sudo latch configure`) |
| `latch-rpm-update` | `%posttrans` upgrade hook (calls `scripts/update.sh`) |
| `latch-httpd.conf` | Apache vhost template → `/etc/httpd/conf.d/latch.conf` |
| `latch-remoteip.conf` | `mod_remoteip` snippet → `/etc/httpd/conf.d/latch-remoteip.conf` (real client IPs in access logs) |
| `fail2ban/` | `latch-login` filter + jail → `/etc/fail2ban/{filter.d,jail.d}/` |
| `systemd/` | `latch-cron-*.timer` replaces crontab for `apache` |

**Operators:** [source/docs/INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md) (install, **`sudo latch backup` / restore**, paths under `/var/lib/latch/storage/`)  
**Maintainer setup:** local `deploy/copr-setup.md` (not in git)

**Backups (RPM):** `%posttrans` and `sudo latch backup` write split archives (`core.tar.gz` + `plugins.tar.gz`) to `/var/lib/latch/storage/backups/`. Keep off-site copies. See [INSTALL-FEDORA.md — Backups](../source/docs/INSTALL-FEDORA.md#backups-and-restore-rpm).

**COPR builds:** enable **Use internet** on the COPR project; `%build` runs `composer install --no-dev` (`BuildRequires: composer`). `source/vendor/` is gitignored — only `composer.lock` is committed.

**Release checklist:** see [docs/RELEASE.md](../docs/RELEASE.md). Bump **all** version surfaces together (`VERSION`, `app.version`, `latch.spec` `Version:`, `SECURITY.md`, RPM `%changelog`); run `./scripts/check-versions.sh` then `scripts/build-release.sh`; tag `v{version}`, GitHub release (tarball + `SHA256SUMS`), COPR/RPM.

## Log paths and admin viewer

Operators can inspect logs from **Admin → Logs** (`/admin/logs`) or `sudo latch logs list|tail`. Built-in sources always include:

- `/var/lib/latch/storage/logs/security.log` (JSON — login, bans, OIDC, etc.)
- `/var/lib/latch/storage/logs/restore.log` (break-glass restore)

**Apache** (from `latch-httpd.conf`, installed as `/etc/httpd/conf.d/latch.conf`):

```apache
ErrorLog  /var/log/httpd/latch-error.log
CustomLog /var/log/httpd/latch-access.log combined
```

fail2ban `latch-login` watches `/var/lib/latch/storage/logs/security.log` by default (`login_fail` JSON with real IPs). Apache paths above are for the admin log viewer; if you change vhost log locations, update `logs.sources[]` in `/etc/latch/local.php`.

**Enabling server logs in the viewer** — opt-in in `/etc/latch/local.php`:

```php
'logs' => [
    'server_logs_enabled' => true,
    'sources' => [
        ['id' => 'httpd.access', 'label' => 'Apache access', 'group' => 'Web server',
         'path' => '/var/log/httpd/latch-access.log', 'format' => 'text'],
        ['id' => 'httpd.error', 'label' => 'Apache error', 'group' => 'Web server',
         'path' => '/var/log/httpd/latch-error.log', 'format' => 'text'],
    ],
],
```

PHP-FPM (user `apache`) must be able to read each configured path. `/var/log/httpd/latch-*.log` is usually group-readable; PHP-FPM slowlog often is not:

```bash
sudo setfacl -m u:apache:r /var/log/php-fpm/www-slow.log
sudo latch logs list    # status: readable | missing | permission_denied
```

Do **not** point `logs.sources` at `config/`, `storage/database/`, `storage/cache/`, or `storage/plugins/` — the registry denylist blocks those paths even if misconfigured.

Operator docs: [source/docs/INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md#server-logs-admin-viewer), [source/docs/SECURITY.md](../source/docs/SECURITY.md#admin-log-viewer), [source/docs/CLI.md](../source/docs/CLI.md#logs).