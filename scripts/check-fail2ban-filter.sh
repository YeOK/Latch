#!/usr/bin/env bash
# Validate packaging/fail2ban/latch-login.conf against sample Apache combined lines.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILTER="$ROOT/packaging/fail2ban/latch-login.conf"

if ! command -v fail2ban-regex >/dev/null 2>&1; then
    echo "skip: fail2ban-regex not installed"
    exit 0
fi

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

cat >"$TMP" <<'EOF'
::1 - - [12/Jul/2026:15:42:27 +0100] "POST /login HTTP/1.1" 200 16348 "https://latch.network/register" "Mozilla/5.0"
203.0.113.9 - - [12/Jul/2026:15:54:51 +0100] "POST /login HTTP/1.1" 200 16236 "-" "-"
198.51.100.2 - - [12/Jul/2026:15:54:57 +0100] "POST /login/2fa HTTP/1.1" 200 16244 "-" "-"
203.0.113.9 - - [12/Jul/2026:15:55:01 +0100] "POST /login HTTP/1.1" 302 0 "-" "-"
EOF

OUT="$(fail2ban-regex "$TMP" "$FILTER" 2>&1)" || true
echo "$OUT"

if echo "$OUT" | grep -q 'Failregex: 0 total'; then
    echo "error: latch-login filter matched 0 sample lines" >&2
    exit 1
fi

if echo "$OUT" | grep -q 'Failregex: 3 total'; then
    echo "ok: latch-login filter matched 3 failed-login samples (302 redirect excluded)"
    exit 0
fi

echo "error: expected 3 failregex hits on sample log" >&2
exit 1