<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\Response;
use Latch\Core\SeoMeta;
use Latch\Core\Sitemap;

final class SeoController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function sitemap(array $params = []): void
    {
        $siteUrl = $this->app->siteUrl();
        $membersOnly = $this->app->membersOnly();
        $cacheRoute = '/sitemap.xml';

        $xml = $this->cachedSitemap($cacheRoute, function () use ($siteUrl, $membersOnly): string {
            $sitemap = new Sitemap();
            $sitemap->addUrl($siteUrl . '/', changefreq: 'daily', priority: '1.0');

            if (!$membersOnly) {
                foreach ($this->app->sitemap()->publicBoards(false) as $board) {
                    $lastmod = (string) ($board['last_post_at'] ?? '');
                    $sitemap->addUrl(
                        $siteUrl . '/board/' . $board['slug'],
                        $lastmod !== '' ? $lastmod : null,
                        'daily',
                        '0.8',
                    );
                }

                $limit = max(1, (int) $this->app->config()->get('forum.sitemap_topics_limit', 5000));
                foreach ($this->app->sitemap()->publicTopics(false, $limit) as $topic) {
                    $sitemap->addUrl(
                        $siteUrl . '/topic/' . $topic['id'],
                        (string) $topic['last_post_at'],
                        'weekly',
                        '0.6',
                    );
                }
            }

            return $sitemap->render();
        });

        Response::sitemapXml($xml, cacheable: $this->canCacheSeo());
    }

    public function robots(array $params = []): void
    {
        $siteUrl = $this->app->siteUrl();
        $lines = ['User-agent: *'];

        if ($this->app->membersOnly()) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . SeoMeta::absoluteUrl($siteUrl, '/sitemap.xml');
        }

        Response::plainText(implode("\n", $lines) . "\n", cacheable: $this->canCacheSeo());
    }

    private function cachedSitemap(string $route, callable $builder): string
    {
        if (!$this->canCacheSeo()) {
            return $builder();
        }

        $cacheKey = Cache::makeKey($route, []);
        $cached = $this->app->cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = $builder();
        $this->app->cache()->set($cacheKey, $xml, $this->app->cacheTtlSeconds(), [Cache::tagSite()]);

        return $xml;
    }

    /** Guest-only — same policy as Application::canUsePageCache(). */
    private function canCacheSeo(): bool
    {
        return $this->app->cacheEnabled()
            && !$this->app->auth()->check()
            && !$this->app->membersOnly();
    }
}