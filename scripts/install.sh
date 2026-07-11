#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# First-time Latch install from a release or git tree (tarball path — not COPR).
#
# Usage:
#   bash scripts/install.sh --url=https://forum.example.com --name="My Forum"
#   bash scripts/install.sh --url=https://forum.example.com --admin-user=admin --admin-email=you@example.com
#
# Options passed through to `php bin/latch install`. Add --no-cron to skip crontab setup.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE="${REPO_ROOT}/source"
WEB_USER="${WEB_USER:-apache}"
SKIP_CRON=0
INSTALL_OPTS=()

for arg in "$@"; do
    case "$arg" in
        --no-cron) SKIP_CRON=1 ;;
        -h|--help)
            sed -n '2,10p' "$0"
            exit 0
            ;;
        *) INSTALL_OPTS+=("$arg") ;;
    esac
done

if [[ ! -f "${SOURCE}/bin/latch" ]]; then
    echo "Error: ${SOURCE}/bin/latch not found — run from the Latch install root." >&2
    exit 1
fi

echo "==> Composer (production dependencies)"
(
    cd "${SOURCE}"
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --no-interaction
    elif [[ -f composer.phar ]]; then
        php composer.phar install --no-dev --optimize-autoloader --no-interaction
    else
        echo "Error: composer not found — install composer or use bundled source/composer.phar" >&2
        exit 1
    fi
    if [[ ! -f vendor/autoload.php ]]; then
        echo "Error: vendor/autoload.php missing after composer install" >&2
        exit 1
    fi
)

echo "==> Forum install"
(
    cd "${SOURCE}"
    php bin/latch install "${INSTALL_OPTS[@]}"
)

echo "==> Doctor"
(
    cd "${SOURCE}"
    php bin/latch doctor
)

echo "==> Audit"
(
    cd "${SOURCE}"
    php bin/latch audit
)

if [[ "${SKIP_CRON}" -eq 0 ]] && [[ "$(id -u)" -eq 0 ]]; then
    echo "==> Cron (root)"
    LATCH_ROOT="${SOURCE}" WEB_USER="${WEB_USER}" bash "${SCRIPT_DIR}/install-cron.sh"
elif [[ "${SKIP_CRON}" -eq 0 ]]; then
    echo ""
    echo "Cron not installed (run as root):"
    echo "  sudo LATCH_ROOT=${SOURCE} WEB_USER=${WEB_USER} bash ${SCRIPT_DIR}/install-cron.sh"
fi

echo ""
echo "Install complete."
echo "  Web root:  ${SOURCE}/public"
echo "  Config:    ${SOURCE}/config/local.php"
echo "  Next:      point Apache/nginx DocumentRoot at public/"
echo "  Plugins:   sudo latch plugin enable <slug>   # RPM — not sudo php bin/latch"
echo "  Perms fix: sudo latch fix-perms              # if plugin settings or audit cache fail"
echo "  Upgrades:  bash ${SCRIPT_DIR}/update.sh"