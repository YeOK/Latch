#!/bin/bash
# Create an OAuth client on latch.network for local API harness testing.
#
# Run from your dev machine in a real terminal (NOT with sudo):
#   ~/Documents/latch/scripts/setup-api-test-client.sh
#
# You will be prompted for:
#   1. SSH password (yeok@192.168.1.6)
#   2. sudo password on the server
#
set -euo pipefail

REMOTE_USER="yeok"
REMOTE_HOST="192.168.1.6"
REMOTE_LATCH="/var/www/latch/source"

print_manual_steps() {
    cat <<'EOF'
Manual setup (if the script cannot get a TTY):

  ssh -t yeok@192.168.1.6

  cd /var/www/latch/source
  sudo -u apache php bin/latch api-client create \
    --name="Local API Harness" \
    --redirect=https://latch.network/oauth/cli-callback \
    --scopes=read,messages:read,messages:write \
    --user=yeok

Copy client_id and client_secret into source/tests/api/config.local.php
EOF
}

if [[ "$(id -u)" -eq 0 ]] || [[ -n "${SUDO_USER:-}" ]]; then
    echo "Do not run this script with sudo on your dev machine." >&2
    echo "Run: ~/Documents/latch/scripts/setup-api-test-client.sh" >&2
    exit 1
fi

if [[ ! -t 0 ]] || [[ ! -t 1 ]]; then
    echo "This script needs an interactive terminal (for SSH + sudo passwords)." >&2
    echo "" >&2
    print_manual_steps
    exit 1
fi

echo "Creating OAuth client on ${REMOTE_HOST}…"
echo "(SSH password, then server sudo password)"
echo ""

REMOTE_CMD="cd ${REMOTE_LATCH} && sudo -u apache php bin/latch api-client create \
  --name='Local API Harness' \
  --redirect=https://latch.network/oauth/cli-callback \
  --scopes=read,messages:read,messages:write \
  --user=yeok"

# -tt: force TTY so nested sudo can prompt for a password
ssh -tt -o StrictHostKeyChecking=accept-new "${REMOTE_USER}@${REMOTE_HOST}" "${REMOTE_CMD}"

echo ""
echo "Next steps on this machine:"
echo "  1. cp source/tests/api/config.example.php source/tests/api/config.local.php"
echo "  2. Paste client_id and client_secret from above"
echo "  3. Optional: set message_recipient to another member username"
echo "  4. cd source && php bin/latch test-api-messages authorize"
echo "  5. php bin/latch test-api-messages run"