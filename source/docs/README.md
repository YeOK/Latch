# Latch documentation

Operator and developer reference under `source/docs/`. Published to the forum Documentation board via `scripts/post-documentation.php` (operator tree only).

## Core

| Document | Topic |
|----------|-------|
| [INSTALL.md](INSTALL.md) | Fresh install and requirements |
| [INSTALL-FEDORA.md](INSTALL-FEDORA.md) | Fedora COPR RPM |
| [UPGRADE.md](UPGRADE.md) | Version upgrades and migrations |
| [CLI.md](CLI.md) | `bin/latch` commands (migrate, backup, test, import, …) |
| [TESTING.md](TESTING.md) | PHPUnit suites, smoke/security gates, OIDC E2E |
| [SECURITY.md](SECURITY.md) | Hardening, audit, and operator checklist |
| [MARKUP.md](MARKUP.md) | Post markup reference (not yet on Documentation board) |

## Features

| Document | Topic |
|----------|-------|
| [API.md](API.md) | REST API and OAuth |
| [OIDC.md](OIDC.md) | Google/GitHub sign-in |
| [EMAIL.md](EMAIL.md) | Outbound mail |
| [WEBHOOKS.md](WEBHOOKS.md) | Outbound webhooks |
| [THEMING.md](THEMING.md) | Themes and `theme.json` |
| [PLUGINS.md](PLUGINS.md) | Plugin API, audit, and examples |
| [PERFORMANCE.md](PERFORMANCE.md) | Caching, search, and tuning |

## Plugin examples

Sample plugins live under [plugins/](plugins/) (not auto-loaded). Copy into `plugins/{slug}/` to try them.

## Related

- Release notes: `CHANGELOG.md` at repo root
- Screenshots: `docs/images/` at repo root