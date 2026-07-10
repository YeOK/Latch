# Latch

A fast, secure, self-hosted PHP forum with SQLite, theming, plugins, and an OAuth API (phased roadmap).

## Why Latch?

Most forums ask you to run a database server, a cache, a job queue, and a dozen moving parts before anyone can post. Latch is the opposite: **one PHP app, one SQLite file, Apache or nginx** — enough for a real community without turning your VPS into a small datacenter.

**You own the whole stack.** Your posts, users, and config live on your disk. No vendor lock-in, no surprise plan changes, no mining your members for ads. MIT licensed — fork it, theme it, extend it.

**Built for operators, not just visitors.** Install, migrate, backup, restore, health checks, and maintenance are first-class CLI commands (`bin/latch`), not wiki archaeology. Site lock quiesces the forum during upgrades; WAL-safe backups and `db-check` catch corruption before it spreads.

**Security is not an afterthought.** Mandatory admin 2FA, strict CSP, session registry, audit logging, board ACLs, report queue, and an `audit` gate for plugins — hardened from Phase 1.5 onward, not bolted on years later.

**Modern forum features, modest footprint.** Full-text search, tags, reactions, DMs, notifications, reputation, OAuth API, webhooks, and a plugin hook system — without Redis, Elasticsearch, or a separate Node process.

**Good fit if you:** want a self-hosted community on a home server or small VPS; are comfortable with PHP and a Unix web stack; value data ownership and operator tooling over managed SaaS.

**Probably not yet if you:** need multi-million-post scale on clustered Postgres today (SQLite has limits); want a fully hosted, zero-ops solution.

### Screenshots

**Boards home** — board list with pinned topics, full-text search, stats, and footer navigation.

![Latch boards home — dark theme with pinned topics and forum stats](docs/images/boards-home.jpg)

**Admin dashboard** — forum stats, system health (database, cache, cron, mail), version panel, and maintenance tools.

![Latch admin dashboard — stats, system panel, and version info](docs/images/admin-dashboard.jpg)

Try it in minutes — download a release tarball, run `php bin/latch install`, point your web server at `source/public/`. See [source/docs/INSTALL.md](source/docs/INSTALL.md).

- **License:** MIT (see [LICENSE](LICENSE))
- **Source:** all code under [`source/`](source/)

## Status

See [CHANGELOG.md](CHANGELOG.md) and [GitHub Releases](https://github.com/YeOK/Latch/releases) for the current version.

## Quick paths

| Path | Purpose |
|------|---------|
| `source/public/` | Web root (only this should be exposed to HTTP) |
| `source/bin/` | CLI tools (`install`, `migrate`, `audit`) |
| `source/docs/` | Installation and developer documentation |
| `source/storage/` | SQLite database and runtime files (keep private) |

## Install (release tarball)

```bash
tar -xzf latch-0.3.0.21.tar.gz && cd latch-0.3.0.21-stage
bash scripts/install.sh --url=https://forum.example.com --name="My Forum"
```

Download: [GitHub Releases](https://github.com/YeOK/Latch/releases) · Build locally: `./scripts/build-release.sh` → `dist/latch-<version>.tar.gz`

Fedora/RHEL COPR: [source/docs/INSTALL-FEDORA.md](source/docs/INSTALL-FEDORA.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md).