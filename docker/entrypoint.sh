#!/usr/bin/env bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# First-run install + migrate for the Latch Docker image.
set -euo pipefail

SOURCE="/var/www/latch/source"
LOCAL="${SOURCE}/config/local.php"
PERSIST_LOCAL="${LATCH_PERSIST_CONFIG:-/persist/config/local.php}"
DB_PATH="${LATCH_DB_PATH:-${SOURCE}/storage/database/latch.sqlite}"
WEB_USER="${APACHE_RUN_USER:-www-data}"
WEB_GROUP="${APACHE_RUN_GROUP:-www-data}"

LATCH_URL="${LATCH_URL:-http://localhost:8080}"
LATCH_NAME="${LATCH_NAME:-Latch}"
LATCH_ADMIN_USER="${LATCH_ADMIN_USER:-admin}"
LATCH_ADMIN_EMAIL="${LATCH_ADMIN_EMAIL:-admin@localhost}"

mkdir -p "${SOURCE}/storage/database" \
    "${SOURCE}/storage/logs" \
    "${SOURCE}/storage/cache" \
    "${SOURCE}/storage/backups" \
    "${SOURCE}/storage/plugins" \
    "${SOURCE}/storage/uploads" \
    "$(dirname "${PERSIST_LOCAL}")"

if [[ -f "${PERSIST_LOCAL}" && ! -f "${LOCAL}" ]]; then
    cp "${PERSIST_LOCAL}" "${LOCAL}"
fi

if [[ -n "${LATCH_ENCRYPTION_KEY:-}" && ! -f "${LOCAL}" ]]; then
    LATCH_LOCAL_PATH="${LOCAL}" php -r '
        $path = getenv("LATCH_LOCAL_PATH");
        $key = getenv("LATCH_ENCRYPTION_KEY");
        $url = getenv("LATCH_URL") ?: "http://localhost:8080";
        $name = getenv("LATCH_NAME") ?: "Latch";
        if (!is_string($path) || $path === "" || !is_string($key) || $key === "") {
            fwrite(STDERR, "missing LATCH_LOCAL_PATH or LATCH_ENCRYPTION_KEY\n");
            exit(1);
        }
        $config = [
            "site" => ["name" => $name, "url" => rtrim($url, "/")],
            "security" => ["encryption_key" => $key, "trust_cloudflare" => false],
        ];
        $export = var_export($config, true);
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n");
    '
fi

cd "${SOURCE}"

as_web() {
    runuser -u "${WEB_USER}" -- "$@"
}

installed=0
if [[ -f "${DB_PATH}" && -f "${LOCAL}" ]]; then
    installed=1
fi

if [[ "${installed}" -eq 0 ]]; then
    ADMIN_PASS="${LATCH_ADMIN_PASS:-}"
    if [[ -z "${ADMIN_PASS}" ]]; then
        ADMIN_PASS="$(php -r 'echo bin2hex(random_bytes(8));')"
        echo "==> Generated admin password (shown once): ${ADMIN_PASS}"
        echo "    Set LATCH_ADMIN_PASS on later recreates, or use Forgot password."
        SECRET_FILE="$(dirname "${PERSIST_LOCAL}")/docker-bootstrap.secret"
        printf '%s\n' "admin_user=${LATCH_ADMIN_USER}" "admin_password=${ADMIN_PASS}" \
            > "${SECRET_FILE}"
        chmod 600 "${SECRET_FILE}" || true
        echo "    Copy also at ${SECRET_FILE} (not under the web root)."
    fi

    echo "==> First-run install (${LATCH_URL})"
    export LATCH_ADMIN_PASS="${ADMIN_PASS}"
    php bin/latch install \
        --url="${LATCH_URL}" \
        --name="${LATCH_NAME}" \
        --admin-user="${LATCH_ADMIN_USER}" \
        --admin-email="${LATCH_ADMIN_EMAIL}" \
        --no-configure
    unset LATCH_ADMIN_PASS
    if [[ "${LATCH_TRUST_CLOUDFLARE:-0}" != "1" && -f "${LOCAL}" ]]; then
        php -r '
            $path = $argv[1];
            $cfg = is_file($path) ? require $path : [];
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $cfg["security"]["trust_cloudflare"] = false;
            $export = var_export($cfg, true);
            file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n");
        ' "${LOCAL}"
    fi
else
    echo "==> Existing install — migrate"
    chown -R "${WEB_USER}:${WEB_GROUP}" "${SOURCE}/storage" || true
    as_web php bin/latch migrate
fi

if [[ -f "${LOCAL}" ]]; then
    mkdir -p "$(dirname "${PERSIST_LOCAL}")"
    cp "${LOCAL}" "${PERSIST_LOCAL}"
fi

chown -R "${WEB_USER}:${WEB_GROUP}" "${SOURCE}/storage" || true
chmod 750 "${SOURCE}/storage" || true
find "${SOURCE}/storage" -type d -exec chmod 750 {} \; || true
if [[ -f "${DB_PATH}" ]]; then
    chmod 660 "${DB_PATH}" || true
fi
if [[ -f "${LOCAL}" ]]; then
    chown "root:${WEB_GROUP}" "${LOCAL}" || true
    chmod 640 "${LOCAL}" || true
fi
if [[ -f "${PERSIST_LOCAL}" ]]; then
    chown "root:${WEB_GROUP}" "${PERSIST_LOCAL}" || true
    chmod 640 "${PERSIST_LOCAL}" || true
fi
SECRET_FILE="$(dirname "${PERSIST_LOCAL}")/docker-bootstrap.secret"
if [[ -f "${SECRET_FILE}" ]]; then
    chown root:root "${SECRET_FILE}" || true
    chmod 600 "${SECRET_FILE}" || true
fi
as_web php bin/latch cron daily >> "${SOURCE}/storage/logs/cron.log" 2>&1 || true

cmd="${1:-apache}"
shift || true

cron_loop() {
    echo "==> Cron sidecar (hourly / daily / weekly)"
    hourly=0
    as_web php bin/latch cron daily >> storage/logs/cron.log 2>&1 || true
    while true; do
        as_web php bin/latch cron hourly >> storage/logs/cron.log 2>&1 || true
        hourly=$((hourly + 1))
        if (( hourly % 24 == 0 )); then
            as_web php bin/latch cron daily >> storage/logs/cron.log 2>&1 || true
        fi
        if (( hourly % 168 == 0 )); then
            as_web php bin/latch cron weekly --audit >> storage/logs/cron.log 2>&1 || true
        fi
        sleep 3600
    done
}

case "${cmd}" in
    cron)
        cron_loop
        ;;
    apache|apache2|apache2-foreground)
        exec apache2-foreground
        ;;
    *)
        exec "${cmd}" "$@"
        ;;
esac
