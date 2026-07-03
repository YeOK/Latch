# Latch

A fast, secure, self-hosted PHP forum with SQLite, theming, plugins, and an OAuth API (phased roadmap).

## Why Latch?

Most forums ask you to run a database server, a cache, a job queue, and a dozen moving parts before anyone can post. Latch is the opposite: **one PHP app, one SQLite file, Apache or nginx** — enough for a real community without turning your VPS into a small datacenter.

**You own the whole stack.** Your posts, users, and config live on your disk. No vendor lock-in, no surprise plan changes, no mining your members for ads. MIT licensed — fork it, theme it, extend it.

**Built for operators, not just visitors.** Install, migrate, backup, restore, health checks, and maintenance are first-class CLI commands (`bin/latch`), not wiki archaeology. Site lock quiesces the forum during upgrades; WAL-safe backups and `db-check` catch corruption before it spreads. A live reference install runs at **[latch.network](https://latch.network)**.

**Security is not an afterthought.** Mandatory admin 2FA, strict CSP, session registry, audit logging, board ACLs, report queue, and an `audit` gate for plugins — hardened from Phase 1.5 onward, not bolted on years later.

**Modern forum features, modest footprint.** Full-text search, tags, reactions, DMs, notifications, reputation, OAuth API, webhooks, and a plugin hook system — without Redis, Elasticsearch, or a separate Node process.

**Good fit if you:** want a self-hosted community on a home server or small VPS; are comfortable with PHP and a Unix web stack; value data ownership and operator tooling over managed SaaS.

**Probably not yet if you:** need multi-million-post scale on clustered Postgres today (SQLite has limits); want a fully hosted, zero-ops solution.

### Screenshots

From the live install at **[latch.network](https://latch.network)**:

**Boards home** — pinned topics, full-text search, light/dark theme, and forum stats.

![Latch boards home — dark theme with pinned topics and forum stats](docs/images/boards-home.jpg)

**Admin dashboard** — site lock for safe upgrades, one-click backup, search rebuild, and moderation queues at a glance.

![Latch admin dashboard — maintenance mode, backups, and forum overview](docs/images/admin-dashboard.jpg)

Try it in minutes — download a release tarball, run `php bin/latch install`, point your web server at `source/public/`. See [source/docs/INSTALL.md](source/docs/INSTALL.md).

- **Plan:** [PLAN.md](PLAN.md)
- **License:** MIT (see [LICENSE](LICENSE))
- **Source:** all code under [`source/`](source/)

## Status

**v0.3.0.2** — public release (Phases 1–4 core + Phase 5 operator tooling).  
Live demo: **[latch.network](https://latch.network)** · Release notes: [CHANGELOG.md](CHANGELOG.md) · [v0.3.0](docs/RELEASE-v0.3.0.md)

## Quick paths

| Path | Purpose |
|------|---------|
| `source/public/` | Web root (only this should be exposed to HTTP) |
| `source/bin/` | CLI tools (`install`, `migrate`, `audit`) |
| `source/docs/` | Installation and developer documentation |
| `source/storage/` | SQLite database and runtime files (keep private) |

## Domain

Production: **https://latch.network** (Cloudflare proxy → home server Apache vhost).

## Install (release tarball)

```bash
tar -xzf latch-0.3.0.2.tar.gz && cd latch-0.3.0.2-stage/source
composer install --no-dev
php bin/latch install --url=https://forum.example.com --name="My Forum"
```

Download: [GitHub Releases](https://github.com/YeOK/Latch/releases) · Build locally: `./scripts/build-release.sh` → `dist/latch-0.3.0.2.tar.gz`

See [source/docs/INSTALL.md](source/docs/INSTALL.md) and [source/docs/UPGRADE.md](source/docs/UPGRADE.md).