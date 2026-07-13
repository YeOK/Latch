#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
#
# Pre-release gate — run from a clean dev tree before build-release.sh or deploy.
#
# Usage:
#   ./scripts/release-gate.sh
#   LATCH_TEST_URL=https://staging.example.com ./scripts/release-gate.sh
#   ./scripts/release-gate.sh --url=https://staging.example.com
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE="${REPO_ROOT}/source"
URL_ARG=""

for arg in "$@"; do
    case "$arg" in
        --url=*) URL_ARG="$arg" ;;
        -h|--help)
            sed -n '2,12p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

if [[ -z "${URL_ARG}" && -n "${LATCH_TEST_URL:-}" ]]; then
    URL_ARG="--url=${LATCH_TEST_URL}"
fi

echo "==> Release gate (Latch $(tr -d '[:space:]' < "${REPO_ROOT}/VERSION"))"
echo ""

echo "==> Version sync"
"${SCRIPT_DIR}/check-versions.sh"
echo ""

echo "==> Composer (dev dependencies)"
(
    cd "${SOURCE}"
    if command -v composer >/dev/null 2>&1; then
        composer install --dev --optimize-autoloader --no-interaction
    elif [[ -f composer.phar ]]; then
        php composer.phar install --dev --optimize-autoloader --no-interaction
    else
        echo "Error: composer not found" >&2
        exit 1
    fi
)
echo ""

echo "==> Full PHPUnit suite"
(
    cd "${SOURCE}"
    php bin/latch test
)
echo ""

echo "==> Security gate"
(
    cd "${SOURCE}"
    php bin/latch test --security
)
echo ""

echo "==> Smoke + plugin audit gate"
(
    cd "${SOURCE}"
    if [[ -n "${URL_ARG}" ]]; then
        php bin/latch test --smoke "${URL_ARG}"
    else
        php bin/latch test --smoke
    fi
)
echo ""

echo "Release gate passed."