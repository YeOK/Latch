#!/bin/bash
# Harden latch.network on the origin server — run with sudo (or via ssh -t).
#
# Usage (from dev machine):
#   ./scripts/install-latch-security.sh
#
# You will be prompted for your sudo password on the server — do not paste it in chat.
#
set -euo pipefail

REMOTE_USER="yeok"
REMOTE_HOST="192.168.1.6"
REMOTE_LATCH_WEBROOT="/var/www/latch"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LATCH_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "Uploading security installer + CLI to ${REMOTE_HOST}..."
ssh "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p /tmp/latch-security-install"
rsync -avz \
    "${LATCH_ROOT}/deploy/server/install-latch-security.sh" \
    "${LATCH_ROOT}/deploy/server/fail2ban-latch-login.conf" \
    "${LATCH_ROOT}/deploy/server/fail2ban-latch-login.local" \
    "${LATCH_ROOT}/source/bin/latch" \
    "${LATCH_ROOT}/source/app/Models/UserRepository.php" \
    "${REMOTE_USER}@${REMOTE_HOST}:/tmp/latch-security-install/"

echo ""
echo "Running on server (enter sudo password when prompted)..."
ssh -t "${REMOTE_USER}@${REMOTE_HOST}" \
    "sudo REMOTE_LATCH_WEBROOT='${REMOTE_LATCH_WEBROOT}' bash /tmp/latch-security-install/install-latch-security.sh"