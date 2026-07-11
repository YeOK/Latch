<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Cache;
use PHPUnit\Framework\TestCase;

final class FragmentCacheTest extends TestCase
{
    public function testFragmentSetGetAndTagInvalidation(): void
    {
        $cacheDir = sys_get_temp_dir() . '/latch-frag-' . bin2hex(random_bytes(4));
        $cache = new Cache($cacheDir);
        $key = Cache::makeFragmentKey('home-board-3', ['_locale' => 'en']);

        $cache->setFragment($key, '<section>board</section>', 300, [Cache::tagBoard(3), Cache::tagSite()]);
        $this->assertSame('<section>board</section>', $cache->getFragment($key));

        $cache->invalidateTag(Cache::tagBoard(3));
        $this->assertNull($cache->getFragment($key));
    }

    public function testTagPluginInvalidationClearsFragments(): void
    {
        $cacheDir = sys_get_temp_dir() . '/latch-frag-plugin-' . bin2hex(random_bytes(4));
        $cache = new Cache($cacheDir);
        $key = Cache::makeFragmentKey('plugin:forum-stats:home.after_boards', ['_locale' => 'en']);

        $cache->setFragment($key, '<section>stats</section>', 300, [Cache::tagPlugin('forum-stats')]);
        $this->assertSame('<section>stats</section>', $cache->getFragment($key));

        $cache->invalidateTag(Cache::tagPlugin('forum-stats'));
        $this->assertNull($cache->getFragment($key));
    }

    public function testPurgeAllClearsFragments(): void
    {
        $cacheDir = sys_get_temp_dir() . '/latch-frag-purge-' . bin2hex(random_bytes(4));
        $cache = new Cache($cacheDir);
        $pageKey = Cache::makeKey('/board/news', ['page' => 1]);
        $fragKey = Cache::makeFragmentKey('board-topics-1-p1', ['_locale' => 'en']);

        $cache->set($pageKey, '<html>page</html>', 300, [Cache::tagBoard(1)]);
        $cache->setFragment($fragKey, '<ul>topics</ul>', 300, [Cache::tagBoard(1)]);

        $this->assertGreaterThanOrEqual(2, $cache->purgeAll());
        $this->assertNull($cache->get($pageKey));
        $this->assertNull($cache->getFragment($fragKey));
    }
}