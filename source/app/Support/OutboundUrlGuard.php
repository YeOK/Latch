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

    /**
     * First public A/AAAA for CURLOPT_RESOLVE pinning (null if the host is blocked).
     */
    public static function resolvedPublicIp(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || self::resolvedHostError($host) !== null) {
            return null;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip']) && self::publicIpError($record['ip']) === null) {
                    return $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6']) && self::publicIpError($record['ipv6']) === null) {
                    return $record['ipv6'];
                }
            }
        }

        $resolved = gethostbyname($host);
        if ($resolved !== $host && self::publicIpError($resolved) === null) {
            return $resolved;
        }

        return null;
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

    /**
     * HTTPS request with DNS pinning when curl is available (SSRF / rebinding).
     *
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: list<string>}|null
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 15,
        int $maxBytes = 1_048_576,
    ): ?array {
        $url = self::normalizePublicHttpsUrl($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        $ipLiteral = self::stripIpv6Brackets($host);
        $pinIp = filter_var($ipLiteral, FILTER_VALIDATE_IP) ? $ipLiteral : self::resolvedPublicIp($host);
        if ($pinIp === null || self::publicIpError($pinIp) !== null) {
            return null;
        }

        if (function_exists('curl_init')) {
            return self::curlPinned($method, $url, $host, $port, $pinIp, $headers, $body, $timeoutSeconds, $maxBytes);
        }

        return self::streamRequest($method, $url, $headers, $body, $timeoutSeconds, $maxBytes);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: list<string>}|null
     */
    private static function curlPinned(
        string $method,
        string $url,
        string $host,
        int $port,
        string $pinIp,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $maxBytes,
    ): ?array {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $resolveHost = str_contains($pinIp, ':') ? "{$host}:{$port}:[{$pinIp}]" : "{$host}:{$port}:{$pinIp}";
        $responseHeaders = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }

                return strlen($headerLine);
            },
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$resolveHost],
        ];
        if (defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            return null;
        }

        return [
            'status' => $status > 0 ? $status : 0,
            'body' => $raw,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: list<string>}|null
     */
    private static function streamRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $maxBytes,
    ): ?array {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            return null;
        }

        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
                $status = (int) $match[1];
            }
        }

        return [
            'status' => $status,
            'body' => $raw,
            'headers' => $responseHeaders,
        ];
    }

    private static function publicIpError(string $ip): ?string
    {
        $canonical = TrustedClientIp::canonicalize($ip) ?? $ip;

        if (filter_var(
            $canonical,
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