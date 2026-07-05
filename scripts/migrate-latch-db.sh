#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Apply migrations when the live SQLite file is owned by the web server user.
# Copies DB to a writable temp path, runs migrate, rsyncs back (passwordless sudo).
#
# Usage:
#   bash scripts/migrate-latch-db.sh
#   LATCH_ROOT=/var/www/latch/source WEB_USER=apache bash scripts/migrate-latch-db.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LATCH_ROOT="${LATCH_ROOT:-${SCRIPT_DIR}/../source}"
WEB_USER="${WEB_USER:-apache}"
WORK_DIR="${WORK_DIR:-${HOME}/latch-migrate}"
DB_PATH="${LATCH_ROOT}/storage/database/latch.sqlite"

if [[ ! -f "${LATCH_ROOT}/bin/latch" ]]; then
    echo "Error: invalid LATCH_ROOT: ${LATCH_ROOT}" >&2
    exit 1
fi

if [[ ! -f "${DB_PATH}" ]]; then
    echo "Error: database not found: ${DB_PATH}" >&2
    exit 1
fi

mkdir -p "${WORK_DIR}"
WORK_DB="${WORK_DIR}/latch.sqlite"

echo "Backing up ${DB_PATH} → ${WORK_DB} (WAL-safe)…"
php "${SCRIPT_DIR}/sqlite-backup.php" "${DB_PATH}" "${WORK_DB}"
chmod u+rw "${WORK_DB}" 2>/dev/null || true

echo "Running migrations…"
(
    cd "${LATCH_ROOT}"
    LATCH_DB_PATH="${WORK_DB}" php -r '
        require "vendor/autoload.php";
        $db = new Latch\Core\Database(getenv("LATCH_DB_PATH"));
        $m = new Latch\Core\Migrator($db, __DIR__ . "/database/migrations");
        $applied = $m->migrate();
        fwrite(STDOUT, "Migrations applied: {$applied}\n");
    '
)

echo "Publishing migrated database…"
if sudo -n rsync -a "${WORK_DB}" "${DB_PATH}" 2>/dev/null; then
    sudo -n chown "${WEB_USER}:${WEB_USER}" "${DB_PATH}" 2>/dev/null || true
elif [[ "$(id -un)" == "${WEB_USER}" ]]; then
    cp -a "${WORK_DB}" "${DB_PATH}"
else
    echo "Error: cannot write ${DB_PATH}. Try: sudo -u ${WEB_USER} php ${LATCH_ROOT}/bin/latch migrate" >&2
    echo "Or grant passwordless sudo for rsync (see scripts/publish-latch-nopass.sh)." >&2
    exit 1
fi

echo "Done."