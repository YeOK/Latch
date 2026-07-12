<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

interface PluginHttpClientInterface
{
    public function get(string $url): ?string;

    /**
     * @return array{status: int, body: string}|null
     */
    public function request(string $method, string $url, ?string $body = null): ?array;
}