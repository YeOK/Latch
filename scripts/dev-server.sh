#!/bin/bash
# Local Latch dev server (PHP built-in — no Apache).
#
# Usage:
#   ./scripts/dev-server.sh
#   ./scripts/dev-server.sh --port 8080
#   ./scripts/dev-server.sh --host 0.0.0.0 --port 8080
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE="${REPO_ROOT}/source"
HOST="127.0.0.1"
PORT="8080"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host=*) HOST="${1#*=}" ;;
        --port=*) PORT="${1#*=}" ;;
        --port) PORT="${2:?}"; shift ;;
        -h|--help)
            sed -n '2,10p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
    shift
done

if [[ ! -f "${SOURCE}/vendor/autoload.php" ]]; then
    echo "==> composer install (with dev deps)"
    (
        cd "${SOURCE}"
        if command -v composer >/dev/null 2>&1; then
            composer install --optimize-autoloader --no-interaction
        else
            php composer.phar install --optimize-autoloader --no-interaction
        fi
    )
fi

LOCAL_PHP="${SOURCE}/config/local.php"
if [[ ! -f "${LOCAL_PHP}" ]]; then
    echo "No config/local.php — run first-time install:"
    echo "  cd source && php bin/latch install --url=http://${HOST}:${PORT} --name='Latch Dev'"
    exit 1
fi

if ! php -m 2>/dev/null | grep -qx sodium; then
    echo "PHP sodium extension is missing — 2FA setup will fail on confirm."
    echo "  sudo dnf install -y php-sodium"
    exit 1
fi

DEV_URL="http://${HOST}:${PORT}"
if ! grep -q "${DEV_URL}" "${LOCAL_PHP}" 2>/dev/null; then
    echo "Note: local.php site.url may not match ${DEV_URL} — redirects/OAuth can misbehave."
    echo "      Dev URL should be http://127.0.0.1:${PORT} (not latch.network)."
fi

echo "==> Latch dev server"
echo "    URL:  ${DEV_URL}"
echo "    Root: ${SOURCE}/public"
echo "    Stop: Ctrl+C"
echo ""

cd "${REPO_ROOT}"
exec php -S "${HOST}:${PORT}" -t "${SOURCE}/public" "${SCRIPT_DIR}/router-dev.php"