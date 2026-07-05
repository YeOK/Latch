#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Install Latch maintenance crontab for the web server user.
#
# Usage:
#   ./scripts/install-cron.sh
#   LATCH_ROOT=/var/www/latch/source WEB_USER=apache ./scripts/install-cron.sh
#
set -euo pipefail

if [[ "$(id -un)" != "root" ]]; then
    echo "Install Latch cron as root (needs crontab -u):" >&2
    echo "  sudo bash $0" >&2
    echo "  LATCH_ROOT=/var/www/latch/source sudo -E bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LATCH_ROOT="${LATCH_ROOT:-${SCRIPT_DIR}/../source}"
WEB_USER="${WEB_USER:-apache}"
CRON_TEMPLATE="${SCRIPT_DIR}/cron/latch.cron.example"
MARKER="# latch-cron-installed"

if [[ ! -f "${LATCH_ROOT}/bin/latch" ]]; then
    echo "Error: LATCH_ROOT does not look like a Latch install: ${LATCH_ROOT}" >&2
    exit 1
fi

if [[ ! -f "${CRON_TEMPLATE}" ]]; then
    echo "Error: missing ${CRON_TEMPLATE}" >&2
    exit 1
fi

mkdir -p "${LATCH_ROOT}/storage/logs"
touch "${LATCH_ROOT}/storage/logs/cron.log"
chown "${WEB_USER}:${WEB_USER}" "${LATCH_ROOT}/storage/logs/cron.log" 2>/dev/null || true

LATCH_ROOT="$(cd "${LATCH_ROOT}" && pwd)"
RENDERED="$(mktemp)"
sed "s|__LATCH_ROOT__|${LATCH_ROOT}|g" "${CRON_TEMPLATE}" > "${RENDERED}"

if ! id "${WEB_USER}" >/dev/null 2>&1; then
    echo "Error: user ${WEB_USER} not found" >&2
    exit 1
fi

EXISTING="$(crontab -u "${WEB_USER}" -l 2>/dev/null || true)"
if echo "${EXISTING}" | grep -q "${MARKER}"; then
    echo "Removing previous Latch cron block for ${WEB_USER}…"
    EXISTING="$(echo "${EXISTING}" | sed "/${MARKER}/,/${MARKER}-end/d")"
fi

{
    echo "${EXISTING}" | sed '/^$/d'
    echo ""
    echo "${MARKER}"
    cat "${RENDERED}"
    echo "${MARKER}-end"
} | crontab -u "${WEB_USER}" -

rm -f "${RENDERED}"

echo "Installed Latch cron for ${WEB_USER} (root: ${LATCH_ROOT})"
echo "Log: ${LATCH_ROOT}/storage/logs/cron.log"
echo "Verify: sudo -u ${WEB_USER} php ${LATCH_ROOT}/bin/latch cron daily"