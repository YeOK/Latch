# Latch RPM packaging (Fedora / COPR)

| File | Purpose |
|------|---------|
| `latch.spec` | RPM spec — bump `Version:` with repo `VERSION` on each release |
| `latch-cli` | `/usr/bin/latch` wrapper |
| `latch-setup` | First-time `bin/latch install` after `dnf install latch` |
| `latch-rpm-update` | `%posttrans` upgrade hook (calls `scripts/update.sh`) |
| `latch-httpd.conf` | Apache vhost template → `/etc/httpd/conf.d/latch.conf` |
| `systemd/` | `latch-cron-*.timer` replaces crontab for `apache` |

**Maintainer:** [docs/COPR.md](../docs/COPR.md)  
**Operators:** [source/docs/INSTALL-FEDORA.md](../source/docs/INSTALL-FEDORA.md)