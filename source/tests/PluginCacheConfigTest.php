<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use InvalidArgumentException;
use Latch\Core\Cache;
use Latch\Core\Plugins\PluginCacheConfig;
use Latch\Core\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginCacheConfigTest extends TestCase
{
    public function testDefaultConfigUsesBakeAndSiteInvalidation(): void
    {
        $config = PluginCacheConfig::default();

        $this->assertSame(PluginCacheConfig::GUEST_PAGE_BAKE, $config->guestPage);
        $this->assertSame(['site'], $config->invalidateOn);
        $this->assertNull($config->fragmentHook);
        $this->assertNull($config->clientRoute);
        $this->assertTrue($config->invalidatesOnSite());
        $this->assertFalse($config->invalidatesOnPlugin());
    }

    public function testFromManifestDataOmitsCacheUsesDefaults(): void
    {
        $config = PluginCacheConfig::fromManifestData(['slug' => 'example']);

        $this->assertSame(PluginCacheConfig::GUEST_PAGE_BAKE, $config->guestPage);
        $this->assertSame(['site'], $config->invalidateOn);
    }

    public function testFromManifestDataParsesFragmentAndPluginInvalidation(): void
    {
        $config = PluginCacheConfig::fromManifestData([
            'cache' => [
                'guest_page' => 'fragment',
                'invalidate_on' => ['plugin', 'site', 'bogus'],
                'fragment' => 'home.after_boards',
            ],
        ]);

        $this->assertTrue($config->isFragment());
        $this->assertSame('home.after_boards', $config->fragmentHook);
        $this->assertEqualsCanonicalizing(['plugin', 'site'], $config->invalidateOn);
        $this->assertTrue($config->invalidatesOnPlugin());
    }

    public function testFromManifestDataParsesClientMode(): void
    {
        $config = PluginCacheConfig::fromManifestData([
            'cache' => [
                'guest_page' => 'client',
                'invalidate_on' => ['plugin'],
                'client' => '/plugin/git-release/widget.json',
            ],
        ]);

        $this->assertTrue($config->isClient());
        $this->assertSame('/plugin/git-release/widget.json', $config->clientRoute);
        $this->assertFalse($config->invalidatesOnSite());
    }

    public function testFromManifestDataRejectsUnknownGuestPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown cache.guest_page 'turbo'");

        PluginCacheConfig::fromManifestData([
            'cache' => ['guest_page' => 'turbo'],
        ]);
    }

    public function testFromManifestDataRejectsNonObjectCache(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Manifest cache must be an object');

        PluginCacheConfig::fromManifestData(['cache' => 'bake']);
    }

    public function testForumStatsManifestParsesCacheBlock(): void
    {
        $manifest = PluginManifest::fromDirectory(CatalogPath::plugin('forum-stats'));

        $this->assertSame(PluginCacheConfig::GUEST_PAGE_BAKE, $manifest->cacheConfig->guestPage);
        $this->assertSame(['site'], $manifest->cacheConfig->invalidateOn);
        $this->assertFalse($manifest->cacheConfig->isBypass());
    }

    public function testTagPluginFormat(): void
    {
        $this->assertSame('plugin:forum-stats', Cache::tagPlugin('forum-stats'));
    }
}