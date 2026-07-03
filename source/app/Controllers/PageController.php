<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\SeoMeta;

final class PageController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function privacy(array $params = []): void
    {
        $this->app->render('privacy/index.html.twig', [
            'operator_name' => $this->app->privacyOperatorName(),
            'contact_email' => $this->app->privacyContactEmail(),
            'seo' => SeoMeta::forPage(
                $this->app->siteUrl(),
                $this->app->siteName(),
                'Privacy policy',
                '/privacy',
                'Privacy policy for ' . $this->app->siteName(),
            )->toArray(),
        ]);
    }

    public function cookies(array $params = []): void
    {
        $this->app->render('cookies/index.html.twig', [
            'operator_name' => $this->app->privacyOperatorName(),
            'contact_email' => $this->app->privacyContactEmail(),
            'gdpr_enabled' => $this->app->gdprEnabled(),
            'seo' => SeoMeta::forPage(
                $this->app->siteUrl(),
                $this->app->siteName(),
                'Cookie policy',
                '/cookies',
                'Cookie policy for ' . $this->app->siteName(),
            )->toArray(),
        ]);
    }
}