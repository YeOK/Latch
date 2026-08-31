<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Support\OutboundUrlGuard;

/**
 * Minimal HTTP GET for plugin catalog and release downloads.
 */
final class PluginHttpClient implements PluginHttpClientInterface
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BODY_BYTES = 33554432;

    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    public function get(string $url): ?string
    {
        $response = $this->request('GET', $url);
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'] !== '' ? $response['body'] : null;
    }

    /**
     * @return array{status: int, body: string}|null
     */
    public function request(string $method, string $url, ?string $body = null): ?array
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $fetched = OutboundUrlGuard::request(
                $method,
                $current,
                ['User-Agent: Latch-PluginCatalog/1.0'],
                $body,
                $this->timeoutSeconds,
                self::MAX_BODY_BYTES,
            );
            if ($fetched === null) {
                return null;
            }

            $headers = $fetched['headers'];
            $status = $fetched['status'] > 0 ? $fetched['status'] : self::statusFromHeaders($headers);
            $raw = $fetched['body'];
            if ($status >= 300 && $status < 400) {
                $location = self::locationFromHeaders($headers);
                if ($location === null) {
                    return null;
                }

                $next = OutboundUrlGuard::resolveRedirectLocation($current, $location);
                if ($next === null) {
                    return null;
                }

                $current = $next;
                $method = 'GET';
                $body = null;
                continue;
            }

            return [
                'status' => $status,
                'body' => $raw,
            ];
        }

        return null;
    }

    /**
     * @param list<string> $headers
     */
    private static function locationFromHeaders(array $headers): ?string
    {
        for ($i = count($headers) - 1; $i >= 0; $i--) {
            if (stripos($headers[$i], 'Location:') === 0) {
                $value = trim(substr($headers[$i], 9));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * PHP keeps every hop in $http_response_header; GitHub release zips 302 to CDN then 200.
     *
     * @param list<string> $headers
     */
    public static function statusFromHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}