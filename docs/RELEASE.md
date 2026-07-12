# Latch release plan (maintainers)

Every public release must bump **all** version surfaces together. Partial bumps (e.g. `VERSION` without `latch.spec`) break COPR, `/health`, and operator upgrades.

## Version surfaces (must match)

| Surface | File / artifact | Example |
|---------|-----------------|---------|
| Tree version | `VERSION` | `0.4.3.0` |
| Config fallback | `source/config/default.php` → `app.version` | `0.4.3.0` |
| RPM spec | `packaging/latch.spec` → `Version:` | `0.4.3.0` |
| Changelog | `CHANGELOG.md` → `## [0.4.3.0]` | dated section, `[Unreleased]` empty |
| Security policy | `SECURITY.md` supported-versions table | latest = current |
| Git tag | `v0.4.3.0` | matches `VERSION` |
| GitHub Release | tag + notes + assets | tarball + `SHA256SUMS` |
| Release tarball | `dist/latch-0.4.3.0.tar.gz` | from `build-release.sh` |
| COPR / RPM | `dnf upgrade latch` | built from tag via `packaging/latch.spec` |

Preflight (must pass before `build-release.sh`):

```bash
./scripts/check-versions.sh
```

## Pre-release checklist

1. **Fold changelog** — move bullets from `## [Unreleased]` into `## [X.Y.Z] — YYYY-MM-DD`; leave `[Unreleased]` header only (no bullets).
2. **Bump all core versions** — same semver in `VERSION`, `app.version`, and `latch.spec` `Version:`.
3. **RPM changelog** — add a `* date … - X.Y.Z-1` entry at the top of `%changelog` in `packaging/latch.spec`.
4. **SECURITY.md** — set the new version as `(latest)` in the supported-versions table.
5. **Tests** — from `source/`:
   ```bash
   php bin/latch test --smoke
   php bin/latch test --security   # if auth, plugins, or JS changed
   php bin/latch audit
   ```
6. **Version sync** — `./scripts/check-versions.sh`
7. **Commit** — e.g. `Release 0.4.3.0: …` (include spec + SECURITY bumps in the same commit as `VERSION`).

## Build and publish

```bash
./scripts/check-versions.sh
./scripts/build-release.sh
```

`build-release.sh` refuses dirty trees (unless `--allow-dirty`) and refuses non-empty `[Unreleased]` bullets.

### Git tag and GitHub Release

```bash
git tag -a v0.4.3.0 -m "Latch 0.4.3.0"
git push origin main
git push origin v0.4.3.0

gh release create v0.4.3.0 \
  dist/latch-0.4.3.0.tar.gz \
  dist/SHA256SUMS \
  --title "Latch v0.4.3.0" \
  --notes-file /tmp/release-notes.md
```

GitHub’s source archive for tag `v0.4.3.0` (`Latch-0.4.3.0.tar.gz`) is what COPR `%prep` consumes — the tag must exist **before** triggering a COPR build.

### COPR / RPM

1. Confirm COPR project watches tags or manually rebuild after the tag is pushed.
2. After the RPM lands: on a staging host, `sudo dnf upgrade latch`, then `sudo latch doctor`, `sudo latch audit`, smoke-test admin and catalog install.
3. Production: same upgrade path; see [INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md).

## Latch-plugins (separate repo)

Plugin catalog versioning is independent but must stay internally consistent:

| Surface | Example |
|---------|---------|
| Catalog release | `catalog.json` → `"release": "v1.0.1"` |
| Bundle zip | `latch-plugins-1.0.1.zip` |
| Per-plugin version | `catalog.json` + `plugin.json` + zip name `{slug}-{version}.zip` |

```bash
./scripts/build-zips.sh v1.0.1
gh release create v1.0.1 releases/*.zip ...
```

See [Latch-plugins README](https://github.com/YeOK/Latch-plugins/blob/main/README.md).

## Common mistakes

- **RPM left on old `Version:`** — `dnf upgrade` never offers the new build; `/usr/share/latch/VERSION` stays stale.
- **Tag without spec bump** — COPR builds the old version from a new tag.
- **Tarball only** — production on Fedora still runs the old RPM.
- **Plugin zip name ≠ catalog `version`** — admin catalog install 404s (e.g. `spam-bridge-1.0.0.zip` vs catalog `1.0.2`).