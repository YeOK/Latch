# Testing checklist

Manual and CLI gates before tagging a release or enabling optional features on production.

## Phase 5 test gate (before OSS tag)

Run on production (needs `php-xml` for full PHPUnit) or CI:

```bash
cd source
php bin/latch doctor          # host, vendor, DB, permissions
php bin/latch test            # full PHPUnit suite
php bin/latch test --smoke    # operator-critical subset + db-check + audit
php bin/latch test --security # security unit tests + audit
php bin/latch audit
```

Release build (from repo root, clean tree):

```bash
./scripts/build-release.sh
```

## Automated (local / CI)

```bash
cd source
composer dump-autoload -o    # after composer.json plugin autoload changes
php bin/latch test
# or: ./vendor/bin/phpunit
```

Production DB permissions: `doctor` fails if `latch.sqlite` is **644** — run `chmod 660` (see `docs/UPGRADE.md`).

Key operator-related suites:

- `tests/SqliteIntegrityTest.php`
- `tests/SiteRestoreTest.php`
- `tests/SiteMaintenanceBackupTest.php`
- `tests/TopicRepositoryHomeTest.php` (home batch queries)

On dev hosts without `php-xml`, use targeted CLI tests:

```bash
php bin/latch test-rss
php bin/latch test-webhooks
php bin/latch test-spam
php bin/latch test-profiles
```

## DB recovery gate (before OSS tag)

On a staging copy with real-ish data:

```bash
php bin/latch lock on
php bin/latch backup
# optional: corrupt a copy and confirm db-check fails
php bin/latch restore --latest
php bin/latch db-check
php bin/latch lock off
```

## OIDC E2E (when providers enabled)

Code ships disabled until `config/local.php` has credentials and admin enables providers.

### latch.network enablement

1. **Google Cloud Console** — OAuth Web client; redirect URI:
   `https://latch.network/auth/oidc/google/callback`
2. **GitHub** — OAuth App; callback:
   `https://latch.network/auth/oidc/github/callback`
3. Add to `config/local.php` on server (not in git):

```php
'oidc' => [
    'google' => ['client_id' => '…', 'client_secret' => '…'],
    'github' => ['client_id' => '…', 'client_secret' => '…'],
],
```

4. **Admin → Settings** — enable Google and/or GitHub under Social sign-in.
5. Confirm login page shows provider buttons.

### E2E checklist

| Step | Expected |
|------|----------|
| New user via Google | Member created; email verified; session established |
| New user via GitHub | Same; uses `/user/emails` if profile email hidden |
| Existing email match | Identity linked; no duplicate user |
| Registration disabled | Social sign-in still works when provider enabled |
| Admin + 2FA | OAuth then TOTP challenge before session |
| Bad callback / CSRF | Redirect to login; `oidc_fail` in `storage/logs/security.log` |
| Revoke sessions | Profile → sessions list; OAuth apps revocable |

### Regression

```bash
php bin/latch audit
php bin/latch test-api --url=https://latch.network   # API unaffected
```

See [OIDC.md](OIDC.md) for provider setup detail.

## Security smoke (production)

- Guest cannot reach `/admin`
- `/storage/` and `/config/local.php` return 404
- Failed login HTTP 200; success 302
- TOTP required for admin accounts
- `php bin/latch audit` passes on server (as `apache` if needed)

## WebAuthn (optional, not implemented)

Deferred — design stub: `docs/design/webauthn.md`. Lower priority than restore/update tooling.