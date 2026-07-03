<?php

declare(strict_types=1);

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
        $locale = Locale::normalize((string) ($params['code'] ?? ''));
        $user = $this->app->auth()->user();

        if ($user !== null) {
            $this->app->users()->updateLocale((int) $user['id'], $locale);
        }

        $this->setLocaleCookie($locale);

        $referer = $this->app->request()->header('Referer');
        $target = is_string($referer) && $referer !== '' && str_starts_with($referer, $this->app->siteUrl())
            ? $referer
            : '/';

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