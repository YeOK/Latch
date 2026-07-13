<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Cache;
use Latch\Core\Plugins\PluginCollectContext;
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\HookRegistry;
use Latch\Core\Plugins\PluginCacheConfig;
use Latch\Core\Plugins\PluginCacheCoordinator;
use Latch\Core\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginCacheCoordinatorTest extends TestCase
{
    private string $storagePath;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/latch-plugin-cache-' . bin2hex(random_bytes(4));
        $this->cache = new Cache($this->storagePath);
    }

    public function testBypassPluginDisablesGuestPageCache(): void
    {
        $coordinator = $this->coordinatorFor([
            $this->manifest('bypass-plug', new PluginCacheConfig(
                guestPage: PluginCacheConfig::GUEST_PAGE_BYPASS,
            )),
        ]);

        $this->assertTrue($coordinator->disablesGuestPageCache());
    }

    public function testBakePluginDoesNotDisableGuestPageCache(): void
    {
        $coordinator = $this->coordinatorFor([
            $this->manifest('forum-stats', PluginCacheConfig::default()),
        ]);

        $this->assertFalse($coordinator->disablesGuestPageCache());
    }

    public function testHasClientModePlugins(): void
    {
        $coordinator = $this->coordinatorFor([
            $this->manifest('git-release', new PluginCacheConfig(
                guestPage: PluginCacheConfig::GUEST_PAGE_CLIENT,
                invalidateOn: ['plugin'],
                clientRoute: '/plugin/git-release/widget.json',
            )),
        ]);

        $this->assertTrue($coordinator->hasClientModePlugins());
    }

    public function testInvalidationTagsIncludePluginSlugWhenConfigured(): void
    {
        $coordinator = $this->coordinatorFor([
            $this->manifest('stats', new PluginCacheConfig(invalidateOn: ['plugin'])),
            $this->manifest('footer', PluginCacheConfig::default()),
        ]);

        $this->assertSame(['plugin:stats'], $coordinator->invalidationTagsForContentChange());
    }

    public function testClientModeEmitsPlaceholder(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(
            HookName::HOME_AFTER_BOARDS,
            static fn (): string => 'should-not-appear',
            10,
            'git-release',
        );

        $coordinator = $this->coordinatorFor([
            $this->manifest('git-release', new PluginCacheConfig(
                guestPage: PluginCacheConfig::GUEST_PAGE_CLIENT,
                invalidateOn: ['plugin'],
                clientRoute: '/plugin/git-release/widget.json',
            )),
        ], $hooks);

        $html = $coordinator->collect($this->collectContext(), HookName::HOME_AFTER_BOARDS);

        $this->assertCount(1, $html);
        $this->assertStringContainsString('data-plugin-client="git-release"', (string) $html[0]);
        $this->assertStringContainsString('data-src="/plugin/git-release/widget.json"', (string) $html[0]);
        $this->assertStringNotContainsString('should-not-appear', (string) $html[0]);
    }

    public function testFragmentModeCachesHookOutputAndServesCachedHtml(): void
    {
        $hooks = new HookRegistry();
        $calls = 0;
        $hooks->add(
            HookName::HOME_AFTER_BOARDS,
            static function () use (&$calls): string {
                $calls++;

                return $calls === 1 ? '<p>first</p>' : '<p>second</p>';
            },
            10,
            'frag-plug',
        );

        $coordinator = $this->coordinatorFor([
            $this->manifest('frag-plug', new PluginCacheConfig(
                guestPage: PluginCacheConfig::GUEST_PAGE_FRAGMENT,
                invalidateOn: ['plugin', 'site'],
                fragmentHook: HookName::HOME_AFTER_BOARDS,
            )),
        ], $hooks);

        $app = $this->collectContext();
        $first = $coordinator->collect($app, HookName::HOME_AFTER_BOARDS);
        $second = $coordinator->collect($app, HookName::HOME_AFTER_BOARDS);

        $this->assertSame(['<p>first</p>'], $first);
        $this->assertSame(['<p>first</p>'], $second);
        $this->assertSame(2, $calls);

        $key = Cache::makeFragmentKey('plugin:frag-plug:' . HookName::HOME_AFTER_BOARDS, ['_locale' => 'en']);
        $this->cache->invalidateTag(Cache::tagPlugin('frag-plug'));
        $this->assertNull($this->cache->getFragment($key));
    }

    public function testClientModeStillRunsThemeAssetsHook(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(
            HookName::THEME_ASSETS,
            static fn (): string => '/plugin/git-release/widget.css?v=1',
            10,
            'git-release',
        );

        $coordinator = $this->coordinatorFor([
            $this->manifest('git-release', new PluginCacheConfig(
                guestPage: PluginCacheConfig::GUEST_PAGE_CLIENT,
                invalidateOn: ['plugin'],
                clientRoute: '/plugin/git-release/widget.json',
            )),
        ], $hooks);

        $assets = $coordinator->collect($this->collectContext(), HookName::THEME_ASSETS);

        $this->assertSame(['/plugin/git-release/widget.css?v=1'], $assets);
    }

    public function testBakeModePassesThroughHookOutput(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(
            HookName::LAYOUT_FOOTER,
            static fn (): string => '<p>baked</p>',
            10,
            'bake-plug',
        );

        $coordinator = $this->coordinatorFor([
            $this->manifest('bake-plug', PluginCacheConfig::default()),
        ], $hooks);

        $this->assertSame(['<p>baked</p>'], $coordinator->collect($this->collectContext(), HookName::LAYOUT_FOOTER));
    }

    private function collectContext(): PluginCollectContext
    {
        return new PluginCacheCoordinatorTestContext($this->cache);
    }

    /**
     * @param list<PluginManifest> $manifests
     */
    private function coordinatorFor(array $manifests, ?HookRegistry $hooks = null): PluginCacheCoordinator
    {
        return new PluginCacheCoordinator($manifests, $hooks ?? new HookRegistry());
    }

    private function manifest(string $slug, PluginCacheConfig $cacheConfig): PluginManifest
    {
        return new PluginManifest(
            name: 'Test ' . $slug,
            slug: $slug,
            version: '1.0.0',
            minLatchVersion: '0.3.0',
            hooks: [HookName::HOME_AFTER_BOARDS, HookName::LAYOUT_FOOTER],
            pluginDir: '/tmp/' . $slug,
            cacheConfig: $cacheConfig,
        );
    }
}

final class PluginCacheCoordinatorTestContext implements PluginCollectContext
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function guestFragmentCacheEnabled(): bool
    {
        return true;
    }

    public function cache(): Cache
    {
        return $this->cache;
    }

    public function cacheTtlSeconds(): int
    {
        return 300;
    }

    public function resolvedLocale(): string
    {
        return 'en';
    }

    public function cspNonce(): string
    {
        return 'test-nonce';
    }
}