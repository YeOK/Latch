# Testing

How to verify Latch before tagging a release, deploying to production, or enabling optional features (OIDC, API clients).

Testing is split into three layers:

1. **PHPUnit** — fast, offline unit and integration tests (no web server required).
2. **CLI gates** — `db-check`, `audit`, and `doctor` against the local install tree.
3. **Live HTTP harnesses** — optional curl-based probes against a running instance (staging or production).

---

## Release gate (before OSS tag)

Run from `source/` on a machine with `php-xml` (PHPUnit) and, for HTTP probes, `php-curl`:

```bash
cd source
php bin/latch doctor
php bin/latch test
php bin/latch test --smoke
php bin/latch test --security
php bin/latch audit
```

**OSS tag rule:** all five commands exit 0 on the release artifact (locally or in CI).

Then build the tarball from the repo root:

```bash
./scripts/build-release.sh
```

On production, run the same gates on the server (as `apache` if file permissions matter):

```bash
cd /var/www/latch/source
sudo -u apache php bin/latch doctor
sudo -u apache php bin/latch test --smoke
sudo -u apache php bin/latch test --security --url=https://latch.network
sudo -u apache php bin/latch audit
```

---

## `bin/latch test` commands

### `php bin/latch test`

Runs the full **Latch** PHPUnit testsuite (~260 tests). Covers repositories, formatters, plugins, moderation, API scopes, migrations, and most application logic.

```bash
php bin/latch test
# equivalent:
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Latch
```

### `php bin/latch test --smoke`

Operator-critical subset for release confidence. Runs in order:

| Step | What it checks |
|------|----------------|
| PHPUnit **smoke** suite | Migrations, SQLite integrity, site lock, restore, plugins, PostFormatter, RSS, cron, home queries, version info, OAuth scopes |
| `db-check` | Integrity + foreign keys on the local database (skipped if no DB) |
| `audit` | File permissions, sensitive paths, security headers class |
| HTTP smoke (optional) | See [Live HTTP harnesses](#live-http-harnesses) |
| API harness (optional) | Runs when `tests/api/config.local.php` exists |

If `php-xml` is missing, falls back to a minimal built-in SQLite integrity check instead of PHPUnit.

### `php bin/latch test --security`

Security regression pack. Runs in order:

| Step | What it checks |
|------|----------------|
| PHPUnit **security** suite | CSRF, XSS/markup, OIDC registration guards, board ACLs, spam controls, plugin audit, **theme JS static scan**, webhook SSRF, TOTP secret encryption, founder protection, input bounds, image-upload BodyGuard |
| HTTP security probes (optional) | Read-only checks against a live URL |
| `audit` | Same self-check as smoke |

---

## PHPUnit testsuites

Defined in `phpunit.xml.dist`:

| Testsuite | Purpose | Run via |
|-----------|---------|---------|
| `Latch` | Full offline suite | `php bin/latch test` |
| `smoke` | Operator-critical paths | `php bin/latch test --smoke` |
| `security` | Security regressions | `php bin/latch test --security` |

**Smoke** includes: `MigratorTest`, `SqliteIntegrityTest`, `SiteRestoreTest`, `SiteLockTest`, `PluginSystemTest`, `PostFormatterTest`, `TopicRepositoryHomeTest`, `CronMaintenanceTest`, `RssFeedTest`, `OAuthScopesTest`, `VersionInfoTest`.

**Security** includes: `CsrfTest`, `SecurityRegressionTest`, `OidcServiceTest`, `BoardAclTest`, `SpamGuardTest`, `PluginAuditorTest`, `PluginAuditServiceTest`, `ThemeJsAuditorTest`, `DatabaseTest`, `OutboundUrlGuardTest`, `SecretCipherTest`, `Phase15LeftoversTest`, `PostEditGuardTest`, `UserRepositoryPurgeTest`, `InputValidatorTest`, `ImageUploadPluginTest`, `RequestHttpsTest`, `SiteLockTest`.

### JavaScript XSS coverage

| Layer | Scope | Gate |
|-------|-------|------|
| **GitHub CodeQL** (`js/xss`, `js/xss-through-dom`) | All tracked JS on `main` | CI on push + weekly |
| **`ThemeJsAuditor`** (`test --security`) | `themes/default/assets/js/*.js` (excludes `*.min.js`) | **Critical** patterns fail PHPUnit; `innerHTML` etc. warn only |
| **`PluginAuditor`** | Third-party plugins only | Critical blocks enable; markup/JS warns; production cache via `PluginAuditService` |

PHPUnit cannot execute browser DOM — server-side `SecurityRegressionTest` covers PHP/Twig escaping only. The theme JS scanner catches regressions like unsanitized `userId` in `href`/`innerHTML` (the class of bug CodeQL flagged in `staff-actions.js`). Broader `innerHTML` use is warned for review; prefer `textContent`, `replaceChildren()`, or DOM APIs.

### Plugin audit cache (runtime)

PHPUnit covers the scanner (`PluginAuditorTest`) and cache/fingerprint behaviour (`PluginAuditServiceTest`). In production:

- Admin **Plugins** uses the file cache when unchanged.
- **`cron daily`** re-scans non-ignored plugins (stats: `plugin_audits_scanned`, `plugin_audits_cached`, `plugin_audits_failed` in `maintenance_runs`).
- **`plugin ignore`** excludes plugins from discovery and audits (CLI only).

See [PLUGINS.md](PLUGINS.md#security-audit).

### SQLite PRAGMA defaults

`DatabaseTest` verifies configurable `database.sqlite` settings from `config/default.php`. Operators tune via `config/local.php` — see [INSTALL.md](INSTALL.md#sqlite-tuning-optional).

Harness helpers under `tests/http/`, `tests/smoke/`, and `tests/security/` are not PHPUnit tests — they are loaded by the CLI when HTTP gates run.

---

## Live HTTP harnesses

PHPUnit gates work offline. HTTP harnesses exercise a **running** Latch instance (local dev server, staging VM, or production).

### Enabling HTTP gates

Pass a base URL on the command line:

```bash
php bin/latch test --security --url=https://latch.network
php bin/latch test --smoke --url=https://latch.network
```

Or copy `tests/smoke/config.example.php` → `tests/smoke/config.local.php` and set `base_url`. The CLI reads this file when `--url` is not passed.

`config.local.php` is gitignored.

### Web security probes (`test --security`)

Read-only — safe to run against production. Implemented in `tests/security/WebSecurityHarness.php`.

| Probe | Expected |
|-------|----------|
| `GET /health` | HTTP 200, JSON `status: ok` |
| `GET /admin` (guest) | HTTP 302 → `/login` |
| `GET /storage/`, `/config/local.php` | Not served (404 or blocked) |
| `GET /login` | Security headers present (CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`) |
| `POST /login` (bad password) | HTTP 200 (fail2ban pattern) |
| `POST /login` (no CSRF) | HTTP 200, "Invalid form token" |
| `POST /locale` (no CSRF) | HTTP 302 redirect, or HTTP 404 if route not deployed yet |
| `GET /auth/oidc/google` (disabled) | HTTP 302 → `/login` |

### Web smoke (`test --smoke`)

Runs all security probes, then:

| Probe | Expected |
|-------|----------|
| `GET /` | HTTP 200 |
| `GET /login` | HTTP 200 with CSRF field |
| `GET /feed.xml` | HTTP 200, valid RSS |

**Optional mutating flow** — when `member_username` and `member_password` are set in `tests/smoke/config.local.php`:

1. Log in as a member (must **not** require TOTP).
2. Create a topic on `board_slug` with a code fence body.
3. Post a reply.
4. Verify rendered output on the topic page.

Use a dedicated test member account, not an admin.

### API harness (`test --smoke` only)

When `tests/api/config.local.php` exists, smoke also runs `tests/api/ApiHarness.php` (OAuth client credentials + read API).

Setup:

```bash
cp tests/api/config.example.php tests/api/config.local.php
# Create a confidential OAuth client on the target instance, then fill in client_id + client_secret.
```

Run API tests alone:

```bash
php bin/latch test-api --url=https://latch.network
php bin/latch test-api-messages    # user-delegated token + DMs (see tests/api/)
```

---

## Configuration files

| File | Purpose |
|------|---------|
| `tests/smoke/config.example.php` | Template for HTTP smoke/security base URL and optional member credentials |
| `tests/smoke/config.local.php` | Your live URL (gitignored) |
| `tests/api/config.example.php` | Template for API OAuth client credentials |
| `tests/api/config.local.php` | API harness secrets (gitignored) |

**Minimal smoke config** (read-only HTTP only):

```php
return [
    'base_url' => 'https://latch.network',
];
```

**Full smoke config** (includes mutating member flow):

```php
return [
    'base_url' => 'https://staging.example.com',
    'member_username' => 'smoke_tester',
    'member_password' => '…',
    'board_slug' => 'general',
];
```

---

## DB recovery gate

On a staging copy with real data, verify backup and restore before tagging:

```bash
php bin/latch lock on
php bin/latch backup
php bin/latch restore --latest
php bin/latch db-check
php bin/latch lock off
```

Optionally corrupt a copy and confirm `db-check` fails before restoring.

---

## Dev environment notes

### PHP extensions

| Extension | Required for |
|-----------|--------------|
| `php-xml` (`dom`, `xml`) | Full PHPUnit (`test`, `--smoke`, `--security`) |
| `php-curl` | Live HTTP harnesses and `test-api` |
| `php-pdo`, `php-mbstring` | All CLI commands |

Install on Fedora: `sudo dnf install php-xml php-curl php-pdo php-mbstring`

### Without `php-xml`

Use targeted built-in CLI test commands:

```bash
php bin/latch test --smoke          # built-in SQLite fallback + db-check + audit
php bin/latch test-rss
php bin/latch test-webhooks
php bin/latch test-spam
php bin/latch test-profiles
```

### Database permissions

`doctor` fails if `latch.sqlite` is world-readable (`644`). Production should use `660` and `apache:apache` (see [UPGRADE.md](UPGRADE.md)).

### Autoload after plugin changes

```bash
composer dump-autoload -o
```

---

## CI playbook (suggested)

```bash
cd source
composer install --no-interaction
composer dump-autoload -o
php bin/latch doctor
php bin/latch test
php bin/latch test --smoke
php bin/latch test --security
php bin/latch audit
cd ..
./scripts/build-release.sh
```

Add HTTP gates in a separate job that has network access and staging credentials:

```bash
php bin/latch test --security --url=$STAGING_URL
php bin/latch test --smoke --url=$STAGING_URL
```

---

## OIDC end-to-end (manual)

OIDC is covered by `OidcServiceTest` in the security suite (registration guards, rate limits). Provider sign-in still needs manual verification when credentials are enabled.

Code ships disabled until `config/local.php` has credentials and admin enables providers in **Settings**.

### Enable on latch.network

1. **Google Cloud Console** — OAuth Web client; redirect URI:
   `https://latch.network/auth/oidc/google/callback`
2. **GitHub** — OAuth App; callback:
   `https://latch.network/auth/oidc/github/callback`
3. Add to server `config/local.php` (not in git):

```php
'oidc' => [
    'google' => ['client_id' => '…', 'client_secret' => '…'],
    'github' => ['client_id' => '…', 'client_secret' => '…'],
],
```

4. **Admin → Settings** — enable Google and/or GitHub under Social sign-in.
5. Confirm login page shows provider buttons.

### Manual checklist

| Step | Expected |
|------|----------|
| New user via Google | Member created; email verified; session established |
| New user via GitHub | Same; uses `/user/emails` if profile email hidden |
| Existing email match | Identity linked; no duplicate user |
| Registration disabled | New OIDC sign-ups blocked; linking existing accounts still works |
| Admin + 2FA | OAuth then TOTP challenge before session |
| Bad callback / CSRF | Redirect to login; `oidc_fail` in `storage/logs/security.log` |
| Revoke sessions | Profile → sessions list; OAuth apps revocable |

### Regression after OIDC changes

```bash
php bin/latch test --security
php bin/latch test-api --url=https://latch.network
```

See [OIDC.md](OIDC.md) for provider setup detail.

---

## Lighthouse (Chrome — manual release check)

Browser quality is checked with **Chrome DevTools → Lighthouse**, not a `bin/latch` command. Run before tagging when you change the theme, CSP, or guest page cache.

1. Open the site in Chrome (local dev server or staging).
2. DevTools → **Lighthouse** tab.
3. Mode: **Navigation**; categories: Performance, Accessibility, Best Practices, SEO.
4. Audit guest **`/`** (home). Optionally repeat on `/board/{slug}` and `/topic/{id}`.
5. Save the JSON report if you want a before/after record.

**Dev baseline (2026-07-05, guest home):** Performance **100**, Accessibility **100**, Best Practices **100**, SEO **100**.

**Production baseline before v0.3.0.16 (v0.3.0.15):** 100 / 94 / 81 / 100 — Best Practices and Accessibility gaps fixed in v0.3.0.16 (CSP cache nonce rewrite, footer link underline, header brand accessible name).

Server-side timing (`bin/latch benchmark`) is separate — it measures PHP/SQLite, not paint or LCP.

---

## Planned (not implemented)

| Command | Purpose |
|---------|---------|
| `bin/latch test --stress` | Concurrency / WAL load scripts (`tests/stress/`) — staging only |
| WebAuthn E2E | Design stub: `docs/design/webauthn.md` |

---

## Quick reference

```bash
php bin/latch doctor                    # preflight: PHP, vendor, DB, permissions
php bin/latch test                      # full PHPUnit (Latch suite)
php bin/latch test --smoke              # operator gate + db-check + audit [+ HTTP/API]
php bin/latch test --security           # security gate + audit [+ HTTP]
php bin/latch test --smoke --url=URL    # smoke with live HTTP + API (if configured)
php bin/latch audit                     # permissions and path self-check
php bin/latch db-check                  # SQLite integrity only
php bin/latch test-api                  # API harness only
```