<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Minimal HTTP GET for plugin catalog and release downloads.
 */
final class PluginHttpClient implements PluginHttpClientInterface
{
    public function __construct(private readonly int $timeoutSeconds = 30)
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
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "User-Agent: Latch-PluginCatalog/1.0\r\n",
                'content' => $body ?? '',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        return [
            'status' => self::statusFromHeaders($http_response_header ?? []),
            'body' => $raw,
        ];
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