#!/bin/bash
# Post-deploy upgrade orchestration for Latch (run on the server after code sync).
#
# Usage:
#   sudo bash scripts/update.sh
#   LATCH_ROOT=/var/www/latch/source WEB_USER=apache sudo -E bash scripts/update.sh
#   sudo bash scripts/update.sh --clear-cache
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LATCH_ROOT="${LATCH_ROOT:-${SCRIPT_DIR}/../source}"
WEB_USER="${WEB_USER:-apache}"
CLEAR_CACHE=0

for arg in "$@"; do
    case "$arg" in
        --clear-cache) CLEAR_CACHE=1 ;;
        -h|--help)
            sed -n '2,12p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

if [[ ! -f "${LATCH_ROOT}/bin/latch" ]]; then
    echo "Error: LATCH_ROOT invalid: ${LATCH_ROOT}" >&2
    exit 1
fi

run_latch() {
    if [[ "$(id -un)" == "${WEB_USER}" ]]; then
        php "${LATCH_ROOT}/bin/latch" "$@"
    else
        sudo -u "${WEB_USER}" php "${LATCH_ROOT}/bin/latch" "$@"
    fi
}

echo "==> Site lock"
run_latch lock on --message="Latch update"

echo "==> Backup"
run_latch backup

echo "==> Composer (if available)"
if [[ -f "${LATCH_ROOT}/composer.json" ]]; then
    if [[ "$(id -un)" == "${WEB_USER}" ]]; then
        (cd "${LATCH_ROOT}" && composer install --no-dev --no-interaction 2>/dev/null) || true
    else
        (cd "${LATCH_ROOT}" && sudo -u "${WEB_USER}" composer install --no-dev --no-interaction 2>/dev/null) || true
    fi
fi

UPDATE_ARGS=(update --skip-lock --skip-backup --assume-files-ready)
if [[ "${CLEAR_CACHE}" != "1" ]]; then
    UPDATE_ARGS+=(--skip-cache)
fi

echo "==> Latch update (migrate, db-check, cron, audit)"
if ! run_latch "${UPDATE_ARGS[@]}"; then
    echo "" >&2
    echo "Update failed — site is still LOCKED." >&2
    echo "Fix the issue above, then:" >&2
    echo "  sudo -u ${WEB_USER} php ${LATCH_ROOT}/bin/latch audit" >&2
    echo "  sudo -u ${WEB_USER} php ${LATCH_ROOT}/bin/latch lock off" >&2
    exit 1
fi

echo "==> Unlock"
run_latch lock off

echo "==> Cron crontab"
LATCH_ROOT="${LATCH_ROOT}" WEB_USER="${WEB_USER}" bash "${SCRIPT_DIR}/install-cron.sh"

echo "Upgrade steps complete. See docs/UPGRADE.md for rollback."