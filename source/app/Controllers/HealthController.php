<?php

declare(strict_types=1);

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
            'version' => (string) $this->app->config()->get('app.version', '0.0.0'),
            'cache_enabled' => $this->app->cacheEnabled(),
        ]);
    }
}