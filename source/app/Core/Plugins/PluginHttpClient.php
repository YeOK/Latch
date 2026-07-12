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
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $match)) {
            $status = (int) $match[0];
        }

        return ['status' => $status, 'body' => $raw];
    }
}