# Latch — self-hosted PHP forum (RPM for Fedora / COPR)
# Sync Version: with repo VERSION file and git tag v{version}
#
# COPR: spec path packaging/latch.spec (maintainer notes: deploy/copr-setup.md, local only)
# Before tagging: cd source && composer install --no-dev --optimize-autoloader && commit vendor/

%global latch_datadir %{_datadir}/latch
%global latch_libdir %{_localstatedir}/lib/latch
# COPR mock may not load systemd-rpm-macros; define explicitly.
%global _unitdir %{_prefix}/lib/systemd/system

Name:           latch
Version:        0.3.0.15
Release:        1%{?dist}
Summary:        Self-hosted PHP + SQLite forum engine

License:        MIT
URL:            https://latch.network
Source0:        https://github.com/YeOK/Latch/archive/v%{version}/Latch-%{version}.tar.gz

BuildArch:      noarch
BuildRequires:  php-cli
BuildRequires:  php-mbstring
BuildRequires:  php-pdo
BuildRequires:  php-xml
BuildRequires:  rsync

Requires:       httpd
Requires:       php-cli
Requires:       php-mbstring
Requires:       php-pdo
Requires:       php-opcache
Requires:       php-xml
Recommends:     msmtp
Recommends:     fail2ban

%description
Latch is a fast, secure, self-hosted forum on PHP and SQLite. This package
installs application code under %{latch_datadir}/source. Forum data (database,
backups, logs) lives under %{latch_libdir}/storage. Configuration and secrets
live in %%{_sysconfdir}/latch/local.php.

After install: sudo latch-setup
After upgrade: handled automatically via %%posttrans (lock, backup, migrate).

%prep
%autosetup -n Latch-%{version}

%build
# COPR/mock builds are offline — production vendor/ must be committed (composer install --no-dev).
cd source
if [ ! -f vendor/autoload.php ]; then
    echo "vendor/autoload.php missing; run: cd source && composer install --no-dev --optimize-autoloader" >&2
    exit 1
fi

%install
install -d %{buildroot}%{latch_datadir}
install -d %{buildroot}%{latch_libdir}
install -d %{buildroot}%{_sysconfdir}/latch
install -d %{buildroot}%{_sysconfdir}/httpd/conf.d
install -d %{buildroot}%{_bindir}
install -d %{buildroot}%{_unitdir}
install -d %{buildroot}%{_sysconfdir}/fail2ban/filter.d
install -d %{buildroot}%{_sysconfdir}/fail2ban/jail.d

# Application tree (exclude operator-only / runtime paths)
rsync -a \
    --exclude='.git' \
    --exclude='source/storage/database/*.sqlite' \
    --exclude='source/storage/database/*.sqlite-*' \
    --exclude='source/storage/logs/*' \
    --exclude='source/storage/cache/*' \
    --exclude='source/storage/backups/*' \
    --exclude='source/config/local.php' \
    --exclude='source/data/' \
    --exclude='deploy/forum-data/' \
    --exclude='deploy/msmtp.conf' \
    --exclude='PLAN.md' \
    --exclude='dist/' \
    --exclude='scripts/sync-latch.sh' \
    --exclude='scripts/publish-latch-server.sh' \
    --exclude='scripts/publish-latch-nopass.sh' \
    --exclude='scripts/publish-forum-db.sh' \
    --exclude='scripts/post-forum-updates.sh' \
    --exclude='scripts/post-documentation.php' \
    --exclude='scripts/post-security-news.php' \
    --exclude='scripts/update-roadmap-post.php' \
    --exclude='scripts/latch-logs.sh' \
    --exclude='scripts/setup-api-test-client.sh' \
    --exclude='scripts/install-latch-security.sh' \
    --exclude='scripts/dev-server.sh' \
    --exclude='scripts/router-dev.php' \
    --exclude='source/public/router-dev.php' \
    ./ %{buildroot}%{latch_datadir}/

install -m 0644 packaging/latch-httpd.conf %{buildroot}%{_sysconfdir}/httpd/conf.d/latch.conf
install -m 0644 source/config/local.php.example %{buildroot}%{_sysconfdir}/latch/local.php.example
install -m 0755 packaging/latch-cli %{buildroot}%{_bindir}/latch
install -m 0755 packaging/latch-setup %{buildroot}%{_bindir}/latch-setup
install -m 0755 packaging/latch-rpm-update %{buildroot}%{latch_datadir}/packaging/latch-rpm-update

install -m 0644 packaging/systemd/latch-cron-hourly.service %{buildroot}%{_unitdir}/latch-cron-hourly.service
install -m 0644 packaging/systemd/latch-cron-hourly.timer %{buildroot}%{_unitdir}/latch-cron-hourly.timer
install -m 0644 packaging/systemd/latch-cron-daily.service %{buildroot}%{_unitdir}/latch-cron-daily.service
install -m 0644 packaging/systemd/latch-cron-daily.timer %{buildroot}%{_unitdir}/latch-cron-daily.timer
install -m 0644 packaging/systemd/latch-cron-weekly.service %{buildroot}%{_unitdir}/latch-cron-weekly.service
install -m 0644 packaging/systemd/latch-cron-weekly.timer %{buildroot}%{_unitdir}/latch-cron-weekly.timer
install -m 0644 packaging/fail2ban/latch-login.conf %{buildroot}%{_sysconfdir}/fail2ban/filter.d/latch-login.conf
install -m 0644 packaging/fail2ban/latch-login.local %{buildroot}%{_sysconfdir}/fail2ban/jail.d/latch-login.local

%pre
getent group apache >/dev/null 2>&1 || groupadd -r apache 2>/dev/null || true
getent passwd apache >/dev/null 2>&1 || useradd -r -g apache -d /usr/share/httpd -s /sbin/nologin apache 2>/dev/null || true

%post
# Symlink config + stateful storage into the application tree
if [ ! -e %{latch_datadir}/source/config/local.php ]; then
    ln -sf %{_sysconfdir}/latch/local.php %{latch_datadir}/source/config/local.php
fi
if [ ! -L %{latch_datadir}/source/storage ]; then
    rm -rf %{latch_datadir}/source/storage
    ln -sf %{latch_libdir}/storage %{latch_datadir}/source/storage
fi

# Runtime state — created here, not in %%files (avoids upgrade conflicts once DB/backups exist).
mkdir -p %{latch_libdir}/storage/{database,backups,logs,cache/twig}
chown -R apache:apache %{latch_libdir}/storage
chmod 2770 %{latch_libdir}/storage
chmod 2770 %{latch_libdir}/storage/database %{latch_libdir}/storage/backups %{latch_libdir}/storage/logs %{latch_libdir}/storage/cache 2>/dev/null || true

systemctl daemon-reload >/dev/null 2>&1 || true
systemctl enable --now latch-cron-hourly.timer latch-cron-daily.timer latch-cron-weekly.timer >/dev/null 2>&1 || true

if command -v fail2ban-client >/dev/null 2>&1; then
    systemctl enable --now fail2ban >/dev/null 2>&1 || true
    fail2ban-client -t >/dev/null 2>&1 || true
    systemctl try-restart fail2ban >/dev/null 2>&1 || systemctl restart fail2ban >/dev/null 2>&1 || true
fi

if [ ! -f %{_sysconfdir}/latch/local.php ]; then
    echo ""
    echo "Latch installed. Next steps:"
    echo "  1. sudo latch-setup --url=https://forum.example.com"
    echo "  2. sudo systemctl enable --now httpd"
    echo "  3. Point DNS at this host (see %{_sysconfdir}/httpd/conf.d/latch.conf)"
    echo ""
fi

%preun
if [ "$1" = "0" ]; then
    systemctl disable --now latch-cron-hourly.timer latch-cron-daily.timer latch-cron-weekly.timer >/dev/null 2>&1 || true
fi

%postun
if [ "$1" -eq 0 ] && command -v fail2ban-client >/dev/null 2>&1; then
    systemctl try-restart fail2ban >/dev/null 2>&1 || true
fi

%posttrans
# Reload PHP so opcache picks up app changes after rpm upgrade.
systemctl try-restart php-fpm >/dev/null 2>&1 || true

if [ -f %{_sysconfdir}/latch/local.php ] && [ -f %{latch_libdir}/storage/database/latch.sqlite ]; then
    %{latch_datadir}/packaging/latch-rpm-update || :
fi

%files
%dir %{_sysconfdir}/latch
%config(noreplace) %{_sysconfdir}/httpd/conf.d/latch.conf
%config(noreplace) %{_sysconfdir}/fail2ban/filter.d/latch-login.conf
%config(noreplace) %{_sysconfdir}/fail2ban/jail.d/latch-login.local
%{_sysconfdir}/latch/local.php.example
%{_bindir}/latch
%{_bindir}/latch-setup
# Do not %%dir storage/ subtrees — live DB/backups cause rpm upgrade file conflicts.
%dir %attr(0750,apache,apache) %{latch_libdir}
%{latch_datadir}
%{_unitdir}/latch-cron-hourly.service
%{_unitdir}/latch-cron-hourly.timer
%{_unitdir}/latch-cron-daily.service
%{_unitdir}/latch-cron-daily.timer
%{_unitdir}/latch-cron-weekly.service
%{_unitdir}/latch-cron-weekly.timer

%changelog
* Sun Jul 05 2026 YeOK <yeokky@gmail.com> - 0.3.0.15-1
- SQLite PRAGMA tuning, plugin audit cache, theme JS security scan, XSS fixes

* Sun Jul 05 2026 YeOK <yeokky@gmail.com> - 0.3.0.14-1
- Account deletion retention, install.sh, security bootstrap, MIT headers, expanded test gates

* Sun Jul 05 2026 YeOK <yeokky@gmail.com> - 0.3.0.13-1
- Post editor live preview and code highlighting; DM delete; UI/header/footer polish; router-dev hygiene

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.12-1
- Security hardening, smoke/security test gates, phpBB import v1, MARKUP docs

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.11-1
- Bulk board topic moderation; post sort on topics; purge all mod trash (admin maintenance)

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.10-1
- Restart php-fpm after upgrade (opcache); guard plugin admin menu items in Twig

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.9-1
- Admin dashboard system panel; footer about text setting; md-import operator plugin; plugin admin SPA fix

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.8-1
- COPR: create %%{latch_libdir} in %%install (%%dir in %%files requires BUILDROOT path)

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.7-1
- Fix dnf upgrade: stop owning /var/lib/latch/storage/* in %%files (runtime data)

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.6-1
- RPM: ship fail2ban latch-login filter/jail; enable on install when fail2ban present

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.5-1
- COPR: define %%{_unitdir} (systemd macros missing in mock)

* Sat Jul 04 2026 YeOK <yeokky@gmail.com> - 0.3.0.4-1
- COPR: ship committed production vendor/ (mock builds have no network)

* Fri Jul 03 2026 YeOK <yeokky@gmail.com> - 0.3.0.3-1
- Initial COPR packaging scaffold (FHS layout, systemd cron, rpm upgrade hook)