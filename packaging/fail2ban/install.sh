#!/usr/bin/env bash
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Install latch-login filter + jail from this directory (git/tarball/Docker host).
# Fedora COPR already installs these files — skip this on RPM hosts unless
# you are testing a filter from a checkout.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

DIR="$(cd "$(dirname "$0")" && pwd)"
FILTER="${DIR}/latch-login.conf"
JAIL="${DIR}/latch-login.local"

if [[ ! -f "$FILTER" ]]; then
    echo "Missing $FILTER" >&2
    exit 1
fi

install -m 644 "$FILTER" /etc/fail2ban/filter.d/latch-login.conf
if [[ ! -f /etc/fail2ban/jail.d/latch-login.local ]]; then
    install -m 644 "$JAIL" /etc/fail2ban/jail.d/latch-login.local
else
    echo "Keeping existing /etc/fail2ban/jail.d/latch-login.local"
fi

fail2ban-client -t
fail2ban-client reload
echo
echo "=== latch-login ==="
fail2ban-client status latch-login
