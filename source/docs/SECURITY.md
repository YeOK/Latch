# Latch security guide

## Threat model

Latch is a self-hosted PHP forum using SQLite. Primary risks:

- Credential stuffing and brute-force login
- XSS via post content (mitigated: `PostFormatter` escaping + host allowlists, strict CSP, safe preview via server-side format)
- CSRF on mutating forms (mitigated: tokens on all POST routes)
- Session hijacking (mitigated: httponly, secure, SameSite cookies; session registry + revoke)
- Direct access to `storage/` (must stay outside DocumentRoot)

## HTTP security headers

Applied on every response via `Latch\Core\SecurityHeaders`:

- `Content-Security-Policy` (strict; no inline scripts in core theme)
- `Strict-Transport-Security` when HTTPS is detected (Cloudflare or origin TLS)
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` (restricts camera, microphone, geolocation)

## Authentication

- Passwords hashed with `password_hash()` (bcrypt/argon as configured by PHP)
- Login failures return **HTTP 200**; successes return **302** (fail2ban-compatible)
- IP rate limiting + per-account lockout after repeated failures
- Password reset tokens: single-use, hashed at rest, time-limited
- Optional email verification before sign-in
- **TOTP two-factor authentication** (RFC 6238) — optional for members/mods; **mandatory for admins**
- Login flow: password → 6-digit TOTP challenge (or one-time recovery code)
- TOTP secrets encrypted at rest (`security.encryption_key` in `config/local.php`)
- **2FA lockout recovery** (operators): recovery codes → `bin/totp-recover.php` → `php bin/latch totp reset <username> --confirm` — see [CLI.md — totp](CLI.md#totp)
- Session list + revoke from profile; all sessions invalidated on password reset
- **Authorized applications** on profile — list OAuth apps you approved and revoke their access tokens (logged to `security.log` as `oauth_app_revoke`)

## Founder account

User id `1` (first installed admin) cannot be demoted or banned by other admins. Blocked attempts are written to the audit log and `storage/logs/security.log`.

## Logging

- **Security log:** `storage/logs/security.log` (JSON lines) — login, reset, ban, reports, founder blocks
- **Audit log:** SQLite `audit_log` table — admin/mod actions, settings changes, report triage
- **Restore log:** `storage/logs/restore.log` (plain text) — break-glass restore when `audit_log` is not writable

### Admin log viewer

**Admin → Logs** (`/admin/logs`) is separate from **Audit log** (`/admin/audit`):

| Page | Answers |
|------|---------|
| Audit log | What did staff do in the app? (SQLite rows) |
| Logs | What is the runtime emitting? (file tail) |

Access requires **admin role + mandatory 2FA** (same gate as sensitive admin pages). Mods cannot open file logs — raw access/error logs may contain cookies, full URLs, and stack traces.

**Built-in sources** (always listed, no extra config):

| ID | File | Format |
|----|------|--------|
| `latch.security` | `storage/logs/security.log` | JSON lines |
| `latch.restore` | `storage/logs/restore.log` | Plain text |

**Server logs** (Apache/nginx, PHP-FPM) are **opt-in**: set `logs.server_logs_enabled` and `logs.sources[]` in `config/local.php`. Paths must resolve under allowed roots (`/var/log` and `{paths.storage}/logs` by default). Latch never accepts a raw path from the browser — only configured source IDs.

Viewer behaviour:

- Bounded reverse tail (default 200 lines, max 500); **Load older** via byte `cursor`
- **Security log** filters: exact `event`, `ip`, `username`, ISO `since`/`until` (event list in `config/default.php` → `logs.security_event_types`)
- **Text logs** substring filter (`q`, case-insensitive)
- **Redaction** on every line (passwords, tokens, `Authorization`, `Cookie` headers) before HTML/JSON/CLI output
- **Rotation** detected via file size/mtime fingerprint — stale cursors reset with a banner
- **Live mode** (`?live=1`) polls `/admin/logs/feed` every 30s (not HTML refresh); feed rate-limited 30/min per admin

Staff access is audited:

| Action | `audit_log.action` | When |
|--------|-------------------|------|
| Open / refresh viewer (HTML) | `logs.view` | Each page load / Refresh |
| Live / JSON feed poll | `logs.feed` | Debounced — at most once per admin+source per 5 minutes |

**CLI parity** (SSH, same reader and filters):

```bash
php bin/latch logs list
php bin/latch logs tail --source=latch.security --event=login_fail --follow
```

On Fedora RPM: `sudo latch logs …`. See [CLI.md — logs](CLI.md#logs).

**Correlating auth abuse** — `login_fail` in `latch.security` is what fail2ban watches by default (real IPs behind Cloudflare). Apache access logs may still show `::1` until `mod_remoteip` is configured; enable server sources in `local.php` to tail both from the admin UI. See [INSTALL-FEDORA.md](INSTALL-FEDORA.md#server-logs-admin-viewer) and `packaging/README.md`.

Common security log `event` values: `login_fail`, `login_success`, `login_banned`, `login_totp_fail`, `oidc_fail`, `ban`, `password_reset_request`, `founder_block`, `oauth_app_revoke`. Full curated list: `config/default.php` → `logs.security_event_types`.

## Client IP behind Cloudflare

`Latch\Core\Request::ip()` uses `REMOTE_ADDR` by default. Behind Cloudflare + a local reverse proxy, Apache often sees `127.0.0.1` / `::1` instead of the visitor.

When a request includes **`CF-Ray`** (set by Cloudflare edge), Latch trusts **`CF-Connecting-IP`** for rate limits, audit logs, and `security.log`. Spoofing is mitigated by requiring `CF-Ray`; also **firewall the origin** so only Cloudflare IP ranges can reach port 80.

**Apache access logs** use the same `REMOTE_ADDR` unless `mod_remoteip` is configured. The COPR RPM installs `/etc/httpd/conf.d/latch-remoteip.conf` so `latch-access.log` (and fail2ban) see real visitor IPs instead of loopback. Reload `httpd` after install; verify with `grep 'POST /login' /var/log/httpd/latch-access.log | tail -3` — the first field should not be `::1` for internet traffic.

Disable if not using Cloudflare: in `config/local.php`:

```php
'security' => ['trust_cloudflare' => false],
```

## HTTPS detection behind a proxy

`Request::isHttps()` and session cookie `Secure` flags use `X-Forwarded-Proto` only when:

1. **Cloudflare** — `CF-Ray` is present and `trust_cloudflare` is not `false` (default), or
2. **Other reverse proxy** — `trust_forwarded_proto` is explicitly `true` in `config/local.php`.

Without either gate, clients cannot spoof HTTPS by sending `X-Forwarded-Proto: https` to a plain HTTP origin.

Verify after deploy: trigger a login and check `storage/logs/security.log` — `ip` should be your public IP, not `::1`.

## fail2ban

Templates ship in `packaging/fail2ban/` (installed by the COPR RPM):

- `latch-login.conf` → `/etc/fail2ban/filter.d/`
- `latch-login.local` → `/etc/fail2ban/jail.d/`

Default `logpath` is `/var/lib/latch/storage/logs/security.log` — matches `"event":"login_fail","ip":"<HOST>"` JSON lines (real client IPs via Cloudflare). Test with `sudo fail2ban-regex /var/lib/latch/storage/logs/security.log /etc/fail2ban/filter.d/latch-login.conf`.

For direct Apache exposure (no proxy), point `logpath` at your access log instead; the filter also matches failed `POST /login` HTTP 200 lines (`^<HOST> -.*"POST /login…" 200`). Loopback is ignored in both modes.

## Backups

```bash
php bin/latch backup
php bin/latch db-check
```

Creates a **WAL-safe** timestamped tarball of `latch.sqlite` and `config/local.php` under `storage/backups/` (mode `0750`). Run `db-check` after migrate, restore, or suspected corruption.

## Corruption and restore

1. **Lock** — `php bin/latch lock on` (blocks web/API; CLI still works)
2. **Diagnose** — `php bin/latch db-check` (exit 1 = problems)
3. **Restore** — `php bin/latch restore --latest` (lock required unless `--force`)
4. **Verify** — restore runs db-check before success exit
5. **Unlock** — `php bin/latch lock off` only after db-check passes

Pre-restore snapshots: `storage/backups/.pre-restore-*.sqlite` (latest symlink). Pruned to three timestamped files.

Forced restore (`--force`) logs `restore.forced` to `audit_log` when writable, else `storage/logs/restore.log`.

Manual `.recover` and break-glass steps: [UPGRADE.md](UPGRADE.md) § Manual recovery.

## GDPR / data rights

- Users can export profile + posts as JSON from `/profile`
- Users can delete their own account (founder excluded); posts remain with anonymised author; the account row is hard-purged after 30 days (daily cron)

## Guest page cache

Cached HTML is only served to unauthenticated visitors on public boards when `members_only` is off. Never caches admin, auth, or personalised pages.

## Static security analysis

| Layer | Scope | When it runs |
|-------|-------|----------------|
| **`bin/latch audit`** | File permissions, `local.php` readability, encryption key | Smoke/security gates, `update` |
| **`PluginAuditor`** | Third-party plugins under `plugins/` | Enable gate, `plugin-audit`, cached admin/cron scans |
| **`ThemeJsAuditor`** | First-party `themes/*/assets/js/` (default + child packs) | `test --security` PHPUnit gate |
| **GitHub CodeQL** | All tracked JS on `main` | CI on push + weekly schedule |

Server-side post markup is escaped in `PostFormatter` (`SecurityRegressionTest`). DOM XSS in theme JS is caught by CodeQL and `ThemeJsAuditor` — prefer `textContent` / DOM APIs over `innerHTML` with user data.

### Plugin audits

- **Cached** at `storage/cache/plugin-audits/` — admin **Plugins** reuses results when files are unchanged.
- **`cron daily`** re-scans all non-ignored installed plugins.
- **`plugin ignore <slug>`** (CLI only) marks `"ignored": true` in `plugin.json` and removes the plugin from discovery and audits — useful for seasonal extensions kept on disk.

Details: [PLUGINS.md](PLUGINS.md), [TESTING.md](TESTING.md).

## Outbound URL policy (SSRF)

User-supplied and operator-configured outbound HTTPS targets are validated by `Latch\Support\OutboundUrlGuard` before any request is sent:

- HTTPS only (no `http://`, `file://`, or other schemes)
- Blocks literal private, loopback, link-local, and reserved IPs (including IPv6 literals such as `[::1]`)
- Blocks `localhost`, `*.localhost`, `*.local`, and `metadata.google.internal`
- Resolves hostnames via DNS and rejects targets that map to non-public addresses
- Redirect hops are re-validated (link-preview and webhook paths do not auto-follow unsafe `Location` headers)

Plugins that fetch arbitrary URLs must declare `permissions.network` and should delegate URL checks to `OutboundUrlGuard` rather than rolling a local allowlist.

## SQLite hardening

- Database file outside `public/`; `doctor` fails on world-readable `latch.sqlite` (expect `660`, group `apache`).
- WAL mode for concurrent reads; `storage/database/` must be writable by the web user (WAL sidecar files).
- Optional performance PRAGMAs (`busy_timeout`, cache, mmap) — [INSTALL.md](INSTALL.md#sqlite-tuning-optional), [PERFORMANCE.md](PERFORMANCE.md).