# COPR packaging — maintainer guide

Latch publishes **Fedora RPMs** via [COPR](https://copr.fedorainfracoloud.org/) so production hosts can `dnf install latch` and `dnf upgrade latch` without rsync from a dev machine.

**Operator install docs:** [source/docs/INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md)

## Goals

| Goal | How |
|------|-----|
| Pull PHP + Apache deps | RPM `Requires:` in `packaging/latch.spec` |
| Preserve forum state on upgrade | Code in `/usr/share/latch/`; DB in `/var/lib/latch/`; config in `/etc/latch/` |
| Safe upgrades | `%posttrans` → `packaging/latch-rpm-update` (wraps `scripts/update.sh`) |
| Auto-build on release | Git tag `v*` → GitHub Action → COPR webhook |
| Prod ≠ dev testing | latch.network consumes COPR only; dev uses `~/Documents/latch` |

## One-time COPR project setup

1. Log in at https://copr.fedorainfracoloud.org/
2. **New project** — e.g. `yeok/latch`
3. **Package source**
   - SCM type: **Git**
   - Clone URL: `https://github.com/YeOK/Latch.git`
   - Committish: `main` (builds use the spec `Version:` + tag checkout — see below)
4. **RPM spec file:** `packaging/latch.spec`
5. **Chroots:** `fedora-43-x86_64`, `fedora-44-x86_64` (add others as needed)
6. **Build triggers:** enable **Webhook** — copy the webhook URL / ID for GitHub

### Version sync checklist (every release)

```bash
# 1. Bump VERSION, CHANGELOG, app.version, README install examples
# 2. Bump Version: in packaging/latch.spec to match VERSION (no v prefix)
# 3. Commit, tag, push
git tag v0.3.0.4
git push origin main --tags
# 4. GitHub Action triggers COPR (or manual Rebuild in COPR UI)
# 5. On prod: sudo dnf upgrade latch
```

| File | Field |
|------|-------|
| `VERSION` | `0.3.0.4` |
| `packaging/latch.spec` | `Version: 0.3.0.4` |
| Git tag | `v0.3.0.4` |
| GitHub release | `v0.3.0.4` |

`Release:` in the spec increments per COPR rebuild (`1`, `2`, …). Bump `Release:` manually if you need a no-code rebuild.

## Auto-build on git tag (GitHub Actions)

Add repository secrets (Settings → Secrets → Actions):

| Secret | Example | Purpose |
|--------|---------|---------|
| `COPR_WEBHOOK_URL` | `https://copr.fedorainfracoloud.org/api/v2/webhooks/...` | Full webhook URL from COPR UI |

Workflow: `.github/workflows/copr-build.yml` (included in repo). On push of tag `v*`, it POSTs to the webhook.

Until secrets are set, trigger builds manually in the COPR UI (**Rebuild**).

## Local spec test (before COPR)

On a Fedora machine with `rpm-build`, `rsync`, `composer`:

```bash
cd ~/Documents/latch
VERSION=$(tr -d '[:space:]' < VERSION)
# Bump packaging/latch.spec Version to match, then:
spectool -g -R packaging/latch.spec
rpmbuild -ba packaging/latch.spec
```

Or from a git archive:

```bash
git archive --prefix=Latch-${VERSION}/ -o /tmp/latch.tar.gz HEAD
# Adjust Source0 in spec for local build, or use COPR directly
```

## What the RPM installs

See `packaging/latch.spec` `%files` and [INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md).

**Never shipped in the RPM:** `local.php` with real secrets, SQLite databases, `deploy/forum-data/`, operator scripts (`sync-latch.sh`, etc.) — same hygiene as `build-release.sh`.

## Production cutover (latch.network)

| Before | After |
|--------|-------|
| `scripts/sync-latch.sh` from dev laptop | `dnf upgrade latch` on server |
| `/var/www/latch/source` | `/usr/share/latch/source` |
| `source/storage` under web root | `/var/lib/latch/storage` |
| `config/local.php` in tree | `/etc/latch/local.php` |
| crontab for `apache` | systemd timers `latch-cron-*.timer` |

Keep `sync-latch.sh` for emergencies only; document in `PLAN.md` as **private / deprecated for prod**.

## Troubleshooting COPR builds

| Failure | Check |
|---------|-------|
| `composer install` fails | `BuildRequires: composer php-xml` in spec |
| `Source0` 404 | `Version` in spec must match an existing GitHub tag `v{Version}` |
| `%posttrans` migrate fails | Staging host: install RPM, restore backup, run `latch doctor` |
| Webhook never fires | GitHub secret `COPR_WEBHOOK_URL`; tag must match `v*` pattern |

## Related

- `packaging/latch.spec` — RPM definition  
- `packaging/latch-setup` — first install helper  
- `packaging/latch-rpm-update` — upgrade hook  
- `scripts/update.sh` — shared upgrade orchestration  
- `PLAN.md` § Fedora RPM + COPR