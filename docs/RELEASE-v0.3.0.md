# Latch v0.3.0 — first public release

**Latch** is a fast, self-hosted PHP forum on SQLite: boards, moderation, OAuth API, plugins, and operator-first CLI tooling. MIT licensed.

Production reference: **[latch.network](https://latch.network)**

---

## Highlights

- **Self-hosted forum** — topics, search, tags, reactions, DMs, notifications, reputation, board ACLs
- **Security-first** — 2FA, CSP, audit log, site lock, WAL-safe backup/restore, `db-check`, `bin/latch audit`
- **Plugins without core hacks** — hook registry, `plugin-audit`, bundled `forum-stats` and `image-upload` examples
- **OAuth API** — REST + client credentials / authorization code (PKCE)
- **Operator CLI** — `install`, `migrate`, `update`, `restore`, `cron`, `doctor`, `test --smoke`
- **216 automated tests** — full PHPUnit suite passes on PHP 8.4

### Bugfixes in v0.3.0

- **Topic delete confirm popover** — moderators deleting a topic from the header no longer get a clipped/hidden confirmation dialog on narrow layouts or right-aligned headers
- **Deploy-friendly asset caching** — theme CSS/JS cache keys include file mtimes; CDN `Cache-Control` no longer marks assets `immutable`, so upgrades propagate within 24h without a manual version bump
- **Test suite hardening** — schema introspection caches are per-database; PHPUnit fixtures match production schema for cron, DMs, and moderation flows

Operators upgrading from a pre-release build behind Cloudflare may want a one-time purge of `/assets/*` if browsers still serve old `staff-actions.js`.

---

## Install

### Requirements

PHP 8.2+, `pdo_sqlite`, `mbstring`, `json`, Apache/nginx + rewrite, Composer (or `composer.phar`)

### Tarball (recommended)

Download **`latch-0.3.0.tar.gz`** and verify:

```bash
sha256sum -c SHA256SUMS
tar -xzf latch-0.3.0.tar.gz
cd latch-0.3.0-stage/source
composer install --no-dev
php bin/latch install --url=https://forum.example.com --name="My Forum"
```

Point the web server **only** at `source/public/`. Keep `storage/` and `config/local.php` private.

### Git clone

```bash
git clone https://github.com/YeOK/Latch.git
cd latch/source
composer install --no-dev
php bin/latch install
```

*(Replace org/repo URL with your public remote when published.)*

### Upgrade existing site

After replacing `app/`, `bin/`, `database/migrations/`, and `vendor/`:

```bash
sudo bash scripts/update.sh
```

See [`source/docs/UPGRADE.md`](source/docs/UPGRADE.md) for rollback (`restore --latest`).

---

## Bundled plugins

| Plugin | Purpose |
|--------|---------|
| `example` | Minimal hook + route tutorial |
| `forum-stats` | Post/topic/member totals on home |
| `image-upload` | R2 presigned image upload (CDN direct; nothing in `storage/`) |

All plugins ship **disabled**. Enable after audit:

```bash
php bin/latch plugin-audit plugins/forum-stats
php bin/latch plugin enable forum-stats
```

Author guide: [`source/docs/PLUGINS.md`](source/docs/PLUGINS.md)

---

## Verify your install

```bash
php bin/latch doctor
php bin/latch test --smoke    # needs php-xml
php bin/latch audit
php bin/latch db-check
```

---

## What’s next

- phpBB 3.3.x import (`bin/latch import phpbb`) — Phase 6
- Packagist / Docker install paths
- i18n template + RTL polish
- Optional `avatar-url` plugin

Full notes: [`CHANGELOG.md`](../CHANGELOG.md)

---

## Artifacts

| File | Description |
|------|-------------|
| `latch-0.3.0.tar.gz` | Sanitized install tree (no secrets, no live DB) |
| `SHA256SUMS` | Checksum for the tarball |

Built with `./scripts/build-release.sh` from tag `v0.3.0`.