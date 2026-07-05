# Contributing to Latch

Thank you for helping improve Latch. This project targets self-hosted PHP 8.2+ forums on SQLite.

## Development setup

```bash
cd source
php composer.phar install
php bin/latch install --url=http://localhost:8080 --name="Latch Dev"
php bin/latch doctor
```

Run the dev server: `bash scripts/dev-server.sh` (from the repo root).

## Tests

```bash
cd source
php vendor/bin/phpunit -c phpunit.xml.dist          # full suite
php bin/latch test --smoke                          # release gate (PHPUnit smoke + db-check + audit)
php bin/latch test --security                       # security suite + audit
```

The **security** suite includes server-side regressions (`SecurityRegressionTest`), **plugin** static audit tests (`PluginAuditorTest`, `PluginAuditServiceTest`), and **theme JS** XSS pattern checks (`ThemeJsAuditorTest`). GitHub **CodeQL** (`js/xss`) runs on `main` for deeper JS data-flow analysis — see [source/docs/TESTING.md](source/docs/TESTING.md).

Optional live HTTP probes:

```bash
cp tests/smoke/config.example.php tests/smoke/config.local.php
# edit base_url, member credentials
php bin/latch test --smoke --url=https://your-staging-forum
# or: LATCH_TEST_URL=https://your-staging-forum php bin/latch test --smoke
```

## Pull requests

1. One logical change per PR when possible.
2. Add or extend PHPUnit coverage for behaviour changes (especially auth, deletion, moderation, cron).
3. Run `php bin/latch test --smoke` before submitting; run `test --security` for auth, plugin, or JS changes.
4. Update `CHANGELOG.md` under `[Unreleased]` for user-visible fixes.
5. Update operator docs when behaviour changes: `source/docs/CLI.md` (commands), `PLUGINS.md` (plugins), `INSTALL.md` / `PERFORMANCE.md` (SQLite), `TESTING.md` (gates).
6. Do not commit secrets (`config/local.php`, API tokens, operator deploy paths).

## Releases

Releases are built from this repo with `scripts/build-release.sh` after folding `[Unreleased]` into a version section and bumping `VERSION`. Fresh installs use:

```bash
bash scripts/install.sh --url=https://forum.example.com --name="My Forum"
```

Fedora/RHEL operators use the COPR RPM (`latch-setup`) instead.

## Licensing

Latch is [MIT licensed](LICENSE). First-party source files include:

```php
/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */
```

New PHP, shell, and theme/plugin asset files should include this header (run `python3 scripts/add-copyright-headers.py` to backfill missed files).

## Code style

- Match existing PHP style: `declare(strict_types=1);`, typed properties, thin controllers, repository SQL with bound parameters.
- Twig templates: reuse existing partials and CSS variables; bump theme asset mtimes ship automatically.
- CLI changes: update `source/docs/CLI.md` when adding commands or flags; mirror plugin workflow in `source/docs/PLUGINS.md`.
- Config knobs: document defaults in `source/config/default.php` comments and `source/config/local.php.example`; add an operator section in `INSTALL.md` when user-tunable.

## Questions

Open a GitHub issue or discussion on [YeOK/Latch](https://github.com/YeOK/Latch).