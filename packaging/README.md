# Latch RPM packaging (Fedora / COPR)

| File | Purpose |
|------|---------|
| `latch.spec` | RPM spec — bump `Version:` with repo `VERSION` on each release |
| `latch-cli` | `/usr/bin/latch` wrapper |
| `latch-setup` | First-time `bin/latch install` after `dnf install latch` |
| `latch-rpm-update` | `%posttrans` upgrade hook (calls `scripts/update.sh`) |
| `latch-httpd.conf` | Apache vhost template → `/etc/httpd/conf.d/latch.conf` |
| `fail2ban/` | `latch-login` filter + jail → `/etc/fail2ban/{filter.d,jail.d}/` |
| `systemd/` | `latch-cron-*.timer` replaces crontab for `apache` |

**Operators:** [source/docs/INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md)  
**Maintainer setup:** local `deploy/copr-setup.md` (not in git)

**COPR builds:** enable **Use internet** on the COPR project; `%build` runs `composer install --no-dev` (`BuildRequires: composer`). `source/vendor/` is gitignored — only `composer.lock` is committed.

**Release checklist:** bump `VERSION`, `source/config/default.php` (`app.version`), and `packaging/latch.spec` (`Version:`); update `SECURITY.md` supported versions; move live `CHANGELOG.md` bullets out of `[Unreleased]` into that version; `review-git-tree.sh` must show `[Unreleased] is empty`; run `scripts/build-release.sh` (runs Composer, stages `vendor/` into the tarball); tag, COPR, GitHub release.