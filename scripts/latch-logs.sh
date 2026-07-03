#!/bin/bash
# Tail Latch logs on the production server (192.168.1.6).
#
# Usage:
#   ./scripts/latch-logs.sh error [lines]    Apache vhost error log
#   ./scripts/latch-logs.sh access [lines]   Apache access log
#   ./scripts/latch-logs.sh php [lines]      PHP-FPM error log
#   ./scripts/latch-logs.sh security [lines] Latch security.log (storage)
#   ./scripts/latch-logs.sh cron [lines]     Latch cron.log (storage)
#   ./scripts/latch-logs.sh all [lines]      All of the above
#
# First-time server setup (once, needs sudo password on the server):
#   ssh -t yeok@192.168.1.6 'sudo bash /home/yeok/deploy-staging/latch/deploy/server/grant-latch-log-access.sh'
#
set -euo pipefail

REMOTE_USER="${REMOTE_USER:-yeok}"
REMOTE_HOST="${REMOTE_HOST:-192.168.1.6}"
LATCH_WEBROOT="${LATCH_WEBROOT:-/var/www/latch}"

TARGET="${1:-error}"
LINES="${2:-50}"

ssh_cmd() {
    ssh -o StrictHostKeyChecking=accept-new "${REMOTE_USER}@${REMOTE_HOST}" "$@"
}

read_apache() {
    local kind="$1"
    local file
    case "$kind" in
        error) file="/var/log/httpd/latch.network-error.log" ;;
        access) file="/var/log/httpd/latch.network-access.log" ;;
        *) echo "Unknown apache log: $kind" >&2; return 1 ;;
    esac

    if ssh_cmd "test -r '${file}'" 2>/dev/null; then
        ssh_cmd "tail -n '${LINES}' '${file}'"
        return 0
    fi
    if ssh_cmd "sudo -n /usr/local/bin/latch-read-logs '${LINES}' '${kind}'" 2>/dev/null; then
        return 0
    fi
    echo "Cannot read ${file}. Run grant-latch-log-access.sh on the server (see script header)." >&2
    return 1
}

read_php() {
    local file="/var/log/php-fpm/www-error.log"
    if ssh_cmd "test -r '${file}'" 2>/dev/null; then
        ssh_cmd "tail -n '${LINES}' '${file}'"
        return 0
    fi
    if ssh_cmd "sudo -n /usr/local/bin/latch-read-logs '${LINES}' php" 2>/dev/null; then
        return 0
    fi
    echo "Cannot read ${file}. Run grant-latch-log-access.sh on the server." >&2
    return 1
}

read_security() {
    local file="${LATCH_WEBROOT}/source/storage/logs/security.log"
    ssh_cmd "test -r '${file}' && tail -n '${LINES}' '${file}'" \
        || echo "(security.log empty or unreadable — normal on a quiet site)"
}

read_cron() {
    local file="${LATCH_WEBROOT}/source/storage/logs/cron.log"
    if ssh_cmd "test -r '${file}'" 2>/dev/null; then
        ssh_cmd "tail -n '${LINES}' '${file}'"
        return 0
    fi
    echo "(cron.log missing — run on server: sudo bash ${LATCH_WEBROOT}/scripts/install-cron.sh)" >&2
    return 1
}

case "$TARGET" in
    error|apache|apache-error)
        read_apache error
        ;;
    access|apache-access)
        read_apache access
        ;;
    php|php-fpm)
        read_php
        ;;
    security|app)
        read_security
        ;;
    cron|maintenance)
        read_cron
        ;;
    all)
        echo "=== latch.network-error.log (last ${LINES}) ==="
        read_apache error || true
        echo ""
        echo "=== latch.network-access.log (last ${LINES}) ==="
        read_apache access || true
        echo ""
        echo "=== php-fpm www-error.log (last ${LINES}) ==="
        read_php || true
        echo ""
        echo "=== storage/logs/security.log (last ${LINES}) ==="
        read_security || true
        echo ""
        echo "=== storage/logs/cron.log (last ${LINES}) ==="
        read_cron || true
        ;;
    -h|--help)
        sed -n '2,12p' "$0"
        ;;
    *)
        echo "Unknown target: ${TARGET}" >&2
        sed -n '2,8p' "$0" >&2
        exit 1
        ;;
esac