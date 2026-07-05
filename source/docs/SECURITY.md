# Latch security guide

## Threat model

Latch is a self-hosted PHP forum using SQLite. Primary risks:

- Credential stuffing and brute-force login
- XSS via post content (mitigated: raw storage, Twig auto-escape in Phase 1)
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
- Session list + revoke from profile; all sessions invalidated on password reset
- **Authorized applications** on profile — list OAuth apps you approved and revoke their access tokens (logged to `security.log` as `oauth_app_revoke`)

## Founder account

User id `1` (first installed admin) cannot be demoted or banned by other admins. Blocked attempts are written to the audit log and `storage/logs/security.log`.

## Logging

- **Security log:** `storage/logs/security.log` (JSON lines) — login, reset, ban, reports, founder blocks
- **Audit log:** SQLite `audit_log` table — admin/mod actions, settings changes, report triage

## Client IP behind Cloudflare

`Latch\Core\Request::ip()` uses `REMOTE_ADDR` by default. Behind Cloudflare + a local reverse proxy, Apache often sees `127.0.0.1` / `::1` instead of the visitor.

When a request includes **`CF-Ray`** (set by Cloudflare edge), Latch trusts **`CF-Connecting-IP`** for rate limits, audit logs, and `security.log`. Spoofing is mitigated by requiring `CF-Ray`; also **firewall the origin** so only Cloudflare IP ranges can reach port 80.

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

Point `logpath` at your Apache access log for latch.network.

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