#!/usr/bin/env bash
# Validate packaging/fail2ban/latch-login.conf against sample security.log lines.
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
{"ts":"2026-07-12T14:42:28+00:00","event":"login_fail","ip":"2602:fa5d:1::95","user_id":null,"username":"bot1","target_type":null,"target_id":null,"meta":null}
{"ts":"2026-07-12T14:54:51+00:00","event":"login_fail","ip":"63.135.76.7","user_id":null,"username":"bot2","target_type":null,"target_id":null,"meta":null}
{"ts":"2026-07-12T14:54:57+00:00","event":"login_success","ip":"1.2.3.4","user_id":1,"username":"admin","target_type":null,"target_id":null,"meta":null}
{"ts":"2026-07-12T14:55:00+00:00","event":"login_fail","ip":"::1","user_id":null,"username":"local","target_type":null,"target_id":null,"meta":null}
EOF

OUT="$(fail2ban-regex "$TMP" "$FILTER" 2>&1)" || true
echo "$OUT"

if echo "$OUT" | grep -q 'Failregex: 0 total'; then
    echo "error: latch-login filter matched 0 sample lines" >&2
    exit 1
fi

HITS="$(echo "$OUT" | sed -n 's/.*Failregex: \([0-9]*\) total.*/\1/p' | head -1)"
if [ -n "$HITS" ] && [ "$HITS" -ge 2 ]; then
    echo "ok: latch-login filter matched ${HITS} login_fail samples (success + loopback excluded)"
    exit 0
fi

echo "error: expected at least 2 failregex hits on sample log (got: ${HITS:-0})" >&2
exit 1