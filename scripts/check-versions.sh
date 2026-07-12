#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Fail if VERSION, app.version, and RPM spec Version diverge.
#
# Usage:
#   ./scripts/check-versions.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

VERSION_FILE="${REPO_ROOT}/VERSION"
DEFAULT_PHP="${REPO_ROOT}/source/config/default.php"
SPEC_FILE="${REPO_ROOT}/packaging/latch.spec"

if [[ ! -f "${VERSION_FILE}" ]]; then
    echo "Error: VERSION file missing" >&2
    exit 1
fi

TREE_VERSION="$(tr -d '[:space:]' < "${VERSION_FILE}")"
if [[ -z "${TREE_VERSION}" ]]; then
    echo "Error: VERSION file is empty" >&2
    exit 1
fi

if [[ ! -f "${DEFAULT_PHP}" ]]; then
    echo "Error: ${DEFAULT_PHP} missing" >&2
    exit 1
fi

APP_VERSION="$(grep -E "'version'\s*=>\s*'" "${DEFAULT_PHP}" | head -1 | sed -E "s/.*'version'\s*=>\s*'([^']+)'.*/\1/")"
if [[ -z "${APP_VERSION}" ]]; then
    echo "Error: could not read app.version from ${DEFAULT_PHP}" >&2
    exit 1
fi

if [[ ! -f "${SPEC_FILE}" ]]; then
    echo "Error: ${SPEC_FILE} missing" >&2
    exit 1
fi

SPEC_VERSION="$(grep -E '^Version:\s+' "${SPEC_FILE}" | head -1 | awk '{print $2}')"
if [[ -z "${SPEC_VERSION}" ]]; then
    echo "Error: could not read Version: from ${SPEC_FILE}" >&2
    exit 1
fi

ERR=0
if [[ "${APP_VERSION}" != "${TREE_VERSION}" ]]; then
    echo "Error: source/config/default.php app.version is ${APP_VERSION}; VERSION is ${TREE_VERSION}" >&2
    ERR=1
fi

if [[ "${SPEC_VERSION}" != "${TREE_VERSION}" ]]; then
    echo "Error: packaging/latch.spec Version is ${SPEC_VERSION}; VERSION is ${TREE_VERSION}" >&2
    ERR=1
fi

if [[ "${ERR}" -ne 0 ]]; then
    echo "Bump VERSION, source/config/default.php (app.version), and packaging/latch.spec (Version:) together." >&2
    echo "See docs/RELEASE.md" >&2
    exit 1
fi

echo "Version sync OK: ${TREE_VERSION} (VERSION, app.version, latch.spec)"