<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Blocks outbound HTTP requests to private, loopback, and link-local targets (SSRF mitigation).
 *
 * Used for operator-configured webhook delivery and plugins that fetch user-supplied URLs.
 */
final class OutboundUrlGuard
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'metadata.google.internal',
    ];

    public static function normalizePublicHttpsUrl(string $url): ?string
    {
        return self::publicHttpsUrlError($url) === null ? trim($url) : null;
    }

    public static function publicHttpsUrlError(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'URL must be a valid HTTPS address.';
        }

        if (!str_starts_with(strtolower($url), 'https://')) {
            return 'URL must use HTTPS.';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'URL must be a valid HTTPS address.';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return 'URL must include a hostname.';
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return 'URL must not target private or reserved addresses.';
        }

        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return 'URL must not target private or reserved addresses.';
        }

        $ipLiteral = self::stripIpv6Brackets($host);
        if (filter_var($ipLiteral, FILTER_VALIDATE_IP)) {
            return self::publicIpError($ipLiteral);
        }

        return self::resolvedHostError($host);
    }

    public static function resolveRedirectLocation(string $baseUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $location, $match) === 1) {
            $scheme = strtolower($match[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        }

        if (!preg_match('/^https?:\/\//i', $location)) {
            $base = parse_url($baseUrl);
            if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
                return null;
            }

            $origin = $base['scheme'] . '://' . $base['host']
                . (isset($base['port']) ? ':' . $base['port'] : '');

            if (str_starts_with($location, '//')) {
                $location = $base['scheme'] . ':' . $location;
            } elseif (str_starts_with($location, '/')) {
                $location = $origin . $location;
            } else {
                $path = (string) ($base['path'] ?? '/');
                $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
                $location = $origin . $dir . $location;
            }
        }

        return self::normalizePublicHttpsUrl($location);
    }

    private static function resolvedHostError(string $host): ?string
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        if ($ips === []) {
            return 'Could not resolve hostname.';
        }

        foreach ($ips as $ip) {
            $error = self::publicIpError($ip);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private static function publicIpError(string $ip): ?string
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return 'URL must not target private or reserved addresses.';
        }

        return null;
    }

    private static function stripIpv6Brackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }
}