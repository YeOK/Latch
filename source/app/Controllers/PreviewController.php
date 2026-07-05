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

final class PreviewController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function post(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->rateLimiter()->tooManyPosts((int) $user['id'], 30, 10)) {
            Response::json(['error' => 'Too many preview requests.'], 429);
        }

        $body = (string) $this->app->request()->input('body', '');
        $bodyError = $this->app->inputValidator()->postBodyError(trim($body));
        if ($bodyError !== null && trim($body) !== '') {
            Response::json(['error' => $bodyError], 400);
        }
        if (trim($body) === '') {
            Response::json(['html' => '']);
        }

        Response::json(['html' => $this->app->postFormatter()->format($body)]);
    }
}