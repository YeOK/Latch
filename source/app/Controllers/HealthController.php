<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Response;

final class HealthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function ping(array $params = []): void
    {
        Response::json([
            'status' => 'ok',
            'version' => $this->app->latchVersion(),
            'cache_enabled' => $this->app->cacheEnabled(),
        ]);
    }
}