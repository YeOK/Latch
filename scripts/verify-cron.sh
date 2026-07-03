#!/bin/bash
# Check whether Latch scheduled maintenance is installed and has run.
#
# Usage (on server):
#   bash scripts/verify-cron.sh
#   LATCH_ROOT=/var/www/latch/source bash scripts/verify-cron.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LATCH_ROOT="${LATCH_ROOT:-${SCRIPT_DIR}/../source}"
WEB_USER="${WEB_USER:-apache}"
DB_PATH="${LATCH_ROOT}/storage/database/latch.sqlite"
CRON_LOG="${LATCH_ROOT}/storage/logs/cron.log"
MARKER="# latch-cron-installed"
ISSUES=0

warn() { echo "WARN: $*"; ISSUES=$((ISSUES + 1)); }
ok() { echo "OK:   $*"; }

if [[ ! -f "${LATCH_ROOT}/bin/latch" ]]; then
    echo "Error: invalid LATCH_ROOT: ${LATCH_ROOT}" >&2
    exit 1
fi

if [[ -f "${CRON_LOG}" ]]; then
    ok "cron.log exists ($(wc -l < "${CRON_LOG}" | tr -d ' ') lines)"
    if [[ ! -s "${CRON_LOG}" ]]; then
        warn "cron.log is empty — crontab may not have fired yet"
    fi
else
    warn "cron.log missing at ${CRON_LOG} (run scripts/install-cron.sh as root)"
fi

if crontab -u "${WEB_USER}" -l 2>/dev/null | grep -q "${MARKER}"; then
    ok "Latch crontab installed for ${WEB_USER}"
    crontab -u "${WEB_USER}" -l 2>/dev/null | grep -E 'latch cron|LATCH_ROOT' || true
elif [[ "$(id -un)" != "root" ]]; then
    warn "Cannot read ${WEB_USER} crontab (run as root: sudo bash scripts/verify-cron.sh)"
else
    warn "Latch crontab not installed for ${WEB_USER} — run: sudo bash scripts/install-cron.sh"
fi

if [[ -f "${DB_PATH}" ]]; then
    WORK_DB="$(mktemp)"
    if php "${SCRIPT_DIR}/sqlite-backup.php" "${DB_PATH}" "${WORK_DB}" 2>/dev/null; then
        LAST="$(LATCH_DB_PATH="${WORK_DB}" php -r '
            require "'"${LATCH_ROOT}"'/vendor/autoload.php";
            $db = new Latch\Core\Database(getenv("LATCH_DB_PATH"));
            $s = new Latch\Models\SettingRepository($db);
            echo (string) ($s->get("last_cron_daily_at") ?? "");
        ' 2>/dev/null || true)"
        if [[ -n "${LAST}" ]]; then
            ok "last_cron_daily_at=${LAST}"
        else
            warn "last_cron_daily_at not set — daily cron has not completed successfully"
        fi
        RUNS="$(LATCH_DB_PATH="${WORK_DB}" php -r '
            require "'"${LATCH_ROOT}"'/vendor/autoload.php";
            $db = new Latch\Core\Database(getenv("LATCH_DB_PATH"));
            try {
                echo (int) $db->pdo()->query("SELECT COUNT(*) FROM maintenance_runs")->fetchColumn();
            } catch (Throwable $e) {
                echo "0";
            }
        ' 2>/dev/null || echo "0")"
        if [[ "${RUNS}" -gt 0 ]]; then
            ok "maintenance_runs rows: ${RUNS}"
        else
            warn "maintenance_runs is empty"
        fi
        rm -f "${WORK_DB}"
    else
        warn "Could not read database for maintenance_runs (permissions?)"
    fi
fi

echo ""
if [[ "${ISSUES}" -eq 0 ]]; then
    echo "Cron looks healthy."
    exit 0
fi

echo "${ISSUES} issue(s). Install/fix:"
echo "  sudo bash ${SCRIPT_DIR}/install-cron.sh"
echo "  sudo -u ${WEB_USER} php ${LATCH_ROOT}/bin/latch cron hourly"
exit 1