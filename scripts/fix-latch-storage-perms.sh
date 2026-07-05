#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Fix storage/ permissions after rsync (no sudo required if the deploy user owns storage/).
# Apache must traverse storage/ and database/ to read latch.sqlite.
set -euo pipefail

WEBROOT="${1:-/var/www/latch}"
STORAGE="${WEBROOT}/source/storage"
WEB_GROUP="${WEB_GROUP:-apache}"

if [[ ! -d "${STORAGE}" ]]; then
    echo "storage/ not found: ${STORAGE}" >&2
    exit 1
fi

chmod 2770 "${STORAGE}"
chgrp "${WEB_GROUP}" "${STORAGE}" 2>/dev/null || true

for dir in database cache logs uploads backups; do
    if [[ -d "${STORAGE}/${dir}" ]]; then
        chmod 2770 "${STORAGE}/${dir}"
        chgrp "${WEB_GROUP}" "${STORAGE}/${dir}" 2>/dev/null || true
    fi
done

if [[ -d "${STORAGE}/cache/twig" ]]; then
    chmod 2770 "${STORAGE}/cache/twig"
    chgrp "${WEB_GROUP}" "${STORAGE}/cache/twig" 2>/dev/null || true
    if command -v sudo >/dev/null 2>&1 && sudo -n find "${STORAGE}/cache/twig" -mindepth 1 -delete 2>/dev/null; then
        :
    else
        find "${STORAGE}/cache/twig" -mindepth 1 -delete 2>/dev/null || true
    fi
    find "${STORAGE}/cache/twig" -type d -exec chmod 2770 {} + 2>/dev/null || true
    find "${STORAGE}/cache/twig" -type f -exec chmod 660 {} + 2>/dev/null || true
fi

if [[ -f "${STORAGE}/database/latch.sqlite" ]]; then
    chmod 660 "${STORAGE}/database/latch.sqlite" 2>/dev/null || true
fi

echo "Storage permissions updated under ${STORAGE}"
ls -ld "${STORAGE}" "${STORAGE}/database" 2>/dev/null || true