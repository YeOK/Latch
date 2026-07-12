<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginCatalogEntry;
use Latch\Core\Plugins\PluginReleaseDownloader;
use PHPUnit\Framework\TestCase;

final class PluginReleaseDownloaderTest extends TestCase
{
    public function testRejectsNonGithubUrl(): void
    {
        $downloader = new PluginReleaseDownloader('YeOK/Latch-plugins');

        $this->expectException(\InvalidArgumentException::class);
        $downloader->downloadUrl('https://evil.example/plugin.zip');
    }

    public function testRejectsWrongRepoPath(): void
    {
        $downloader = new PluginReleaseDownloader('YeOK/Latch-plugins');

        $this->expectException(\InvalidArgumentException::class);
        $downloader->downloadUrl('https://github.com/other/repo/releases/download/v1.0.0/foo.zip');
    }

    public function testFallsBackToGithubApiWhenDirectUrlMissing(): void
    {
        $entry = PluginCatalogEntry::fromArray([
            'slug' => 'word-filter',
            'name' => 'Word filter',
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'summary' => 'Profanity filter',
            'hooks' => ['post.before_save'],
        ]);
        $directUrl = $entry->releaseZipUrl('YeOK/Latch-plugins', 'v9.9.9');
        $apiUrl = 'https://api.github.com/repos/YeOK/Latch-plugins/releases/tags/v9.9.9';
        $assetUrl = 'https://github.com/YeOK/Latch-plugins/releases/download/v1.0.0/word-filter-1.0.0.zip';

        $http = new StubPluginHttpClient([
            $directUrl => ['status' => 404, 'body' => ''],
            $apiUrl => [
                'status' => 200,
                'body' => json_encode([
                    'assets' => [
                        ['name' => 'word-filter-1.0.0.zip', 'browser_download_url' => $assetUrl],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            $assetUrl => ['status' => 200, 'body' => "PK\x05\x06"],
        ]);
        $downloader = new PluginReleaseDownloader('YeOK/Latch-plugins', $http);

        $path = $downloader->downloadEntry($entry, 'v9.9.9');

        try {
            $this->assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function testDownloadsAllowedReleaseZip(): void
    {
        $entry = PluginCatalogEntry::fromArray([
            'slug' => 'forum-stats',
            'name' => 'Forum stats',
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'summary' => 'Totals',
            'hooks' => ['home.after_boards'],
        ]);
        $url = $entry->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.1');

        $http = new StubPluginHttpClient([
            $url => [
                'status' => 200,
                'body' => "PK\x05\x06",
            ],
        ]);
        $downloader = new PluginReleaseDownloader('YeOK/Latch-plugins', $http);

        $path = $downloader->downloadEntry($entry, 'v1.0.1');

        try {
            $this->assertFileExists($path);
            $this->assertSame("PK\x05\x06", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}