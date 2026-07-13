<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginCatalog;
use PHPUnit\Framework\TestCase;

final class PluginCatalogTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/latch-plugin-catalog-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->cacheFile = $dir . '/catalog.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }

        $dir = dirname($this->cacheFile);
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    public function testParsesCatalogJson(): void
    {
        $catalog = new PluginCatalog($this->cacheFile);
        $parsed = $catalog->parseCatalogJson($this->sampleCatalogJson());

        $this->assertSame('Latch plugin catalog', $parsed['name']);
        $this->assertSame('v1.0.1', $parsed['release']);
        $this->assertCount(2, $parsed['entries']);
        $this->assertSame('forum-stats', $parsed['entries'][0]->slug);
        $this->assertSame('1.0.0', $parsed['entries'][0]->version);
    }

    public function testCachesFetchedCatalog(): void
    {
        $http = new StubPluginHttpClient([
            'https://example.test/catalog.json' => [
                'status' => 200,
                'body' => $this->sampleCatalogJson(),
            ],
        ]);

        $catalog = new PluginCatalog(
            $this->cacheFile,
            'https://example.test/catalog.json',
            'YeOK/Latch-plugins',
            3600,
            $http,
        );

        $first = $catalog->load();
        $second = $catalog->load();

        $this->assertNotNull($first);
        $this->assertSame('v1.0.1', $second['release'] ?? null);
        $this->assertSame(1, $http->requestCount);
        $this->assertFileExists($this->cacheFile);
    }

    public function testAvailableEntriesSkipsInstalledSlugs(): void
    {
        file_put_contents($this->cacheFile, json_encode([
            'catalog_url' => PluginCatalog::DEFAULT_CATALOG_URL,
            'fetched_at' => time(),
            'data' => json_decode($this->sampleCatalogJson(), true),
        ], JSON_THROW_ON_ERROR));

        $catalog = new PluginCatalog($this->cacheFile);
        $available = $catalog->availableEntries(['forum-stats']);

        $this->assertCount(1, $available);
        $this->assertSame('word-filter', $available[0]->slug);
    }

    public function testFindUpdateEntryWhenCatalogVersionIsNewer(): void
    {
        file_put_contents($this->cacheFile, json_encode([
            'catalog_url' => PluginCatalog::DEFAULT_CATALOG_URL,
            'fetched_at' => time(),
            'data' => json_decode($this->sampleCatalogJson(), true),
        ], JSON_THROW_ON_ERROR));

        $catalog = new PluginCatalog($this->cacheFile);
        $entry = $catalog->findUpdateEntry('forum-stats', '0.9.0');

        $this->assertNotNull($entry);
        $this->assertSame('1.0.0', $entry->version);
        $this->assertNull($catalog->findUpdateEntry('forum-stats', '1.0.0'));
        $this->assertNull($catalog->findUpdateEntry('missing', '0.1.0'));
    }

    public function testReleaseZipUrl(): void
    {
        $catalog = new PluginCatalog($this->cacheFile);
        $entry = $catalog->parseCatalogJson($this->sampleCatalogJson())['entries'][1];

        $this->assertSame(
            'https://github.com/YeOK/Latch-plugins/releases/download/v1.0.1/word-filter-1.0.0.zip',
            $entry->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.1'),
        );
    }

    private function sampleCatalogJson(): string
    {
        return <<<'JSON'
{
    "name": "Latch plugin catalog",
    "latch_min_version": "0.4.0",
    "release": "v1.0.1",
    "plugins": [
        {
            "slug": "forum-stats",
            "name": "Forum stats",
            "version": "1.0.0",
            "min_latch_version": "0.3.0",
            "summary": "Home totals",
            "hooks": ["home.after_boards"]
        },
        {
            "slug": "word-filter",
            "name": "Word filter",
            "version": "1.0.0",
            "min_latch_version": "0.3.0",
            "summary": "Profanity filter",
            "hooks": ["post.before_save"]
        }
    ]
}
JSON;
    }
}