<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginHttpClientInterface;

final class StubPluginHttpClient implements PluginHttpClientInterface
{
    public int $requestCount = 0;

    /**
     * @param array<string, array{status: int, body: string}> $responses
     */
    public function __construct(private readonly array $responses)
    {
    }

    public function request(string $method, string $url, ?string $body = null): ?array
    {
        ++$this->requestCount;

        return $this->responses[$url] ?? null;
    }

    public function get(string $url): ?string
    {
        $response = $this->request('GET', $url);

        return $response['body'] ?? null;
    }
}