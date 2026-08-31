<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Who may send Cloudflare / forwarded client-IP headers.
 *
 * CF-Ray + CF-Connecting-IP are trivial to spoof unless REMOTE_ADDR is the
 * edge (Cloudflare ranges) or a local tunnel (loopback). Extra CIDRs come
 * from security.trusted_proxy_cidrs in local.php.
 */
final class TrustedClientIp
{
    /** Cloudflare anycast (https://www.cloudflare.com/ips-v4 / ips-v6). */
    private const CLOUDFLARE_CIDRS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    private const LOOPBACK_CIDRS = [
        '127.0.0.0/8',
        '::1/128',
    ];

    /**
     * @param list<string> $extraCidrs
     */
    public static function isTrustedProxy(string $remoteAddr, array $extraCidrs = []): bool
    {
        $ip = self::canonicalize($remoteAddr);
        if ($ip === null) {
            return false;
        }

        foreach (array_merge(self::LOOPBACK_CIDRS, self::CLOUDFLARE_CIDRS, $extraCidrs) as $cidr) {
            if (!is_string($cidr) || $cidr === '') {
                continue;
            }
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function canonicalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $v4 = inet_ntop(substr($packed, 12));

            return is_string($v4) ? $v4 : null;
        }

        $canon = inet_ntop($packed);

        return is_string($canon) ? $canon : null;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $ip = self::canonicalize($ip);
        if ($ip === null) {
            return false;
        }

        $parts = explode('/', $cidr, 2);
        $network = self::canonicalize($parts[0]);
        if ($network === null) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $maxPrefix = strlen($ipBin) === 4 ? 32 : 128;
        $prefix = isset($parts[1]) ? (int) $parts[1] : $maxPrefix;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remain = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remain === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $remain)) - 1)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }
}
