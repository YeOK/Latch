#!/bin/bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Build a sanitized public release tarball (no secrets, DB, or operator deploy scripts).
#
# Usage:
#   ./scripts/build-release.sh
#   ./scripts/build-release.sh --allow-dirty
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE="${REPO_ROOT}/source"
DIST="${REPO_ROOT}/dist"
ALLOW_DIRTY=0

for arg in "$@"; do
    case "$arg" in
        --allow-dirty) ALLOW_DIRTY=1 ;;
        -h|--help)
            sed -n '2,8p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

if [[ ! -f "${REPO_ROOT}/VERSION" ]]; then
    echo "Error: VERSION file missing" >&2
    exit 1
fi

VERSION="$(tr -d '[:space:]' < "${REPO_ROOT}/VERSION")"
STAGE="${DIST}/latch-${VERSION}-stage"
ARCHIVE="${DIST}/latch-${VERSION}.tar.gz"

echo "==> Release gate (tests, security, smoke, plugin audits)"
"${SCRIPT_DIR}/release-gate.sh"

if [[ -f "${REPO_ROOT}/CHANGELOG.md" ]]; then
    UNRELEASED_BULLETS="$(awk '
        /^## \[Unreleased\]/ { in_unreleased=1; next }
        /^## \[/ && in_unreleased { exit }
        in_unreleased && /^- / { count++ }
        END { print count + 0 }
    ' "${REPO_ROOT}/CHANGELOG.md")"
    if [[ "${UNRELEASED_BULLETS}" -gt 0 ]]; then
        echo "Error: CHANGELOG.md has ${UNRELEASED_BULLETS} bullet(s) under [Unreleased]." >&2
        echo "Fold them into ## [${VERSION}] (or bump VERSION) before building a release." >&2
        exit 1
    fi
fi

if command -v git >/dev/null 2>&1 && git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [[ "${ALLOW_DIRTY}" != "1" ]] && [[ -n "$(git -C "${REPO_ROOT}" status --porcelain 2>/dev/null)" ]]; then
        echo "Error: working tree not clean. Commit or pass --allow-dirty." >&2
        exit 1
    fi
fi

echo "==> Composer (no-dev)"
(
    cd "${SOURCE}"
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --no-interaction
    elif [[ -f composer.phar ]]; then
        php composer.phar install --no-dev --optimize-autoloader --no-interaction
    else
        echo "Error: composer not found — install composer or use bundled source/composer.phar" >&2
        exit 1
    fi
    if [[ ! -f vendor/autoload.php ]]; then
        echo "Error: vendor/autoload.php missing after composer install" >&2
        exit 1
    fi
)

rm -rf "${STAGE}"
mkdir -p "${STAGE}" "${DIST}"

echo "==> Stage release tree"
rsync -a \
    --exclude='.git' \
    --exclude='.DS_Store' \
    --exclude='source/storage/database/*.sqlite' \
    --exclude='source/storage/database/*.sqlite-*' \
    --exclude='source/storage/logs/*' \
    --exclude='source/storage/cache/*' \
    --exclude='source/storage/backups/*' \
    --exclude='source/data/' \
    --exclude='source/config/local.php' \
    --exclude='source/tests/api/config.local.php' \
    --exclude='source/tests/api/user-token.local.json' \
    --exclude='source/tests/api/pkce.local.json' \
    --exclude='source/tests/smoke/config.local.php' \
    --exclude='source/sqlite:' \
    --exclude='source/sqlite:*/' \
    --exclude='source/storage/plugins/*' \
    --exclude='source/storage/database/*' \
    --exclude='PLAN.md' \
    --exclude='docs/design/' \
    --exclude='docs/RELEASE-v0.3.0.md' \
    --exclude='deploy/forum-data/' \
    --exclude='deploy/msmtp.conf' \
    --exclude='deploy/server/fail2ban-latch-login.local' \
    --exclude='scripts/sync-latch.sh' \
    --exclude='scripts/publish-latch-server.sh' \
    --exclude='scripts/publish-latch-nopass.sh' \
    --exclude='scripts/publish-forum-db.sh' \
    --exclude='scripts/post-forum-updates.sh' \
    --exclude='scripts/post-documentation.php' \
    --exclude='scripts/post-security-news.php' \
    --exclude='scripts/update-roadmap-post.php' \
    --exclude='source/plugins/md-import/' \
    --exclude='source/plugins/git-release/' \
    --exclude='source/plugins/link-preview/' \
    --exclude='scripts/latch-logs.sh' \
    --exclude='scripts/setup-api-test-client.sh' \
    --exclude='scripts/install-latch-security.sh' \
    --exclude='scripts/dev-server.sh' \
    --exclude='scripts/router-dev.php' \
    --exclude='dist/' \
    "${REPO_ROOT}/" "${STAGE}/"

echo "==> Secret scrub"
if find "${STAGE}/source/storage/backups" -type f ! -name '.gitkeep' -print -quit 2>/dev/null | grep -q .; then
    echo "Error: backup archives must not ship in release (source/storage/backups/)" >&2
    exit 1
fi
if find "${STAGE}/source/data" -type f -print -quit 2>/dev/null | grep -q .; then
    echo "Error: operator forum-post data must not ship in release (source/data/)" >&2
    exit 1
fi
if grep -RInE '(BEGIN (RSA |EC )?PRIVATE KEY|client_secret["\x27]\s*=>|encryption_key["\x27]\s*=>\s*["\x27][^"\x27]{8,})' \
    "${STAGE}/source" --exclude-dir=vendor 2>/dev/null | grep -v 'docs/' | grep -v 'tests/' ; then
    echo "Error: possible secret in staged tree (see above)" >&2
    exit 1
fi
if grep -RInE '(henpen\.(dev|org)|noreply@henpen\.org|yeok@192\.168|192\.168\.1\.6|/home/yeok/|latch\.network|images\.latch\.network)' \
    "${STAGE}/source" --exclude-dir=vendor 2>/dev/null ; then
    echo "Error: operator-specific hostnames or paths in staged source tree (see above)" >&2
    exit 1
fi
if [[ -f "${STAGE}/PLAN.md" ]] || [[ -d "${STAGE}/deploy/forum-data" ]] || [[ -f "${STAGE}/deploy/msmtp.conf" ]]; then
    echo "Error: operator-only paths must not ship (PLAN.md, deploy/forum-data/, deploy/msmtp.conf)" >&2
    exit 1
fi
if [[ -f "${STAGE}/source/public/router-dev.php" ]]; then
    echo "Error: source/public/router-dev.php must not ship (dev-only router belongs in scripts/)" >&2
    exit 1
fi

echo "==> Archive"
tar -czf "${ARCHIVE}" -C "${DIST}" "latch-${VERSION}-stage"
(
    cd "${DIST}"
    sha256sum "$(basename "${ARCHIVE}")" > "SHA256SUMS"
)

BYTES="$(wc -c < "${ARCHIVE}")"
echo ""
echo "Release: ${ARCHIVE} (${BYTES} bytes)"
echo "Checksum: ${DIST}/SHA256SUMS"
echo "Install:  tar -xzf $(basename "${ARCHIVE}") && cd latch-${VERSION}-stage && bash scripts/install.sh --url=https://forum.example.com"
echo "          (upgrade existing: bash scripts/update.sh)"