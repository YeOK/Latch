<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Locale;
use Latch\Core\Response;

final class LocaleController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function switch(array $params = []): void
    {
        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/');
        }

        $locale = Locale::normalize((string) $this->app->request()->input('locale', ''));
        $user = $this->app->auth()->user();

        if ($user !== null) {
            $this->app->users()->updateLocale((int) $user['id'], $locale);
        }

        $this->setLocaleCookie($locale);

        $target = $this->app->request()->safeRedirectFromReferer(
            $this->app->request()->header('Referer'),
            $this->app->siteUrl(),
        );

        Response::redirect($target);
    }

    private function setLocaleCookie(string $locale): void
    {
        setcookie(
            Locale::COOKIE,
            $locale,
            [
                'expires' => time() + 86400 * 365,
                'path' => '/',
                'secure' => $this->app->request()->isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ],
        );
    }
}