# Latch

A fast, secure, self-hosted PHP forum with SQLite, theming, plugins, and an OAuth API (phased roadmap).

- **Plan:** [PLAN.md](PLAN.md)
- **License:** MIT (see [LICENSE](LICENSE))
- **Source:** all code under [`source/`](source/)

## Status

**v0.3.0** — first public release (Phases 1–4 core + Phase 5 operator tooling).  
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
tar -xzf latch-0.3.0.tar.gz && cd latch-0.3.0-stage/source
composer install --no-dev
php bin/latch install --url=https://forum.example.com --name="My Forum"
```

Build artifact: `./scripts/build-release.sh` → `dist/latch-0.3.0.tar.gz`

See [source/docs/INSTALL.md](source/docs/INSTALL.md) and [source/docs/UPGRADE.md](source/docs/UPGRADE.md).