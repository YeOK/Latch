<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Plugins\GitRelease\GithubReleases;
use Latch\Plugins\GitRelease\HttpTransport;
use Latch\Plugins\GitRelease\ReleaseCache;
use PHPUnit\Framework\TestCase;

final class GitReleasePluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/git-release')) {
            $this->markTestSkipped('git-release plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/git-release';
        $prefix = 'Latch\\Plugins\\GitRelease\\';
        $baseDir = $this->pluginDir . '/src/';

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testReleaseCacheServesFreshEntryWithoutNetwork(): void
    {
        $cacheDir = sys_get_temp_dir() . '/git-release-cache-' . bin2hex(random_bytes(4));
        $cache = new ReleaseCache($cacheDir);
        $release = [
            'tag' => 'v1.0.0',
            'name' => 'Latch 1.0.0',
            'url' => 'https://github.com/YeOK/Latch/releases/tag/v1.0.0',
            'published' => '2026-07-01T00:00:00Z',
            'prerelease' => false,
            'body_excerpt' => 'First release',
            'repo_url' => 'https://github.com/YeOK/Latch',
        ];
        $cache->put('YeOK/Latch', $release);

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('get');

        $github = new GithubReleases($transport, $cache);
        $result = $github->latestRelease('YeOK/Latch', 300);

        $this->assertSame($release, $result);

        $this->assertDirectoryExists($cacheDir);
        array_map('unlink', glob($cacheDir . '/*.json') ?: []);
        rmdir($cacheDir);
    }

    public function testReleaseCacheFallsBackToStaleWhenNetworkFails(): void
    {
        $cacheDir = sys_get_temp_dir() . '/git-release-cache-' . bin2hex(random_bytes(4));
        $cache = new ReleaseCache($cacheDir);
        $release = [
            'tag' => 'v0.9.0',
            'name' => 'Latch 0.9.0',
            'url' => 'https://github.com/YeOK/Latch/releases/tag/v0.9.0',
            'published' => '2026-06-01T00:00:00Z',
            'prerelease' => false,
            'body_excerpt' => 'Older release',
            'repo_url' => 'https://github.com/YeOK/Latch',
        ];
        $cache->put('YeOK/Latch', $release);

        $path = $cacheDir . '/' . hash('sha256', strtolower('YeOK/Latch')) . '.json';
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $payload['fetched_at'] = time() - 7200;
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('get')->willReturn(null);

        $github = new GithubReleases($transport, $cache);
        $result = $github->latestRelease('YeOK/Latch', 300);

        $this->assertSame($release, $result);

        array_map('unlink', glob($cacheDir . '/*.json') ?: []);
        rmdir($cacheDir);
    }

    public function testLatestReleaseExtractsKeepAChangelogSectionForTag(): void
    {
        $changelogBody = <<<'MD'
# Changelog

All notable changes to Latch are documented here.

## [Unreleased]

Work in progress on `main`.

## [0.4.6.0] — 2026-07-13

### Added
- **Forum UI cards** — home board panels and topic header card styling.

### Fixed
- **Client-mode plugin assets** — theme.assets still run for guest_page client plugins.
MD;

        $payload = json_encode([
            'tag_name' => 'v0.4.6.0',
            'name' => 'Latch 0.4.6.0',
            'html_url' => 'https://github.com/YeOK/Latch/releases/tag/v0.4.6.0',
            'published_at' => '2026-07-13T12:00:00Z',
            'prerelease' => false,
            'body' => $changelogBody,
        ], JSON_THROW_ON_ERROR);

        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('get')
            ->with('https://api.github.com/repos/YeOK/Latch/releases/latest')
            ->willReturn($payload);

        $github = new GithubReleases($transport);
        $result = $github->latestRelease('YeOK/Latch', 300);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Forum UI cards', $result['body_excerpt']);
        $this->assertStringNotContainsString('Unreleased', $result['body_excerpt']);
        $this->assertStringNotContainsString('Keep a Changelog', $result['body_excerpt']);
    }

    public function testReleaseCachePurgeAllRemovesEntries(): void
    {
        $cacheDir = sys_get_temp_dir() . '/git-release-cache-' . bin2hex(random_bytes(4));
        $cache = new ReleaseCache($cacheDir);
        $release = [
            'tag' => 'v1.0.0',
            'name' => 'Test',
            'url' => 'https://github.com/foo/bar/releases/tag/v1.0.0',
            'published' => '2026-07-01T00:00:00Z',
            'prerelease' => false,
            'body_excerpt' => 'Notes',
            'repo_url' => 'https://github.com/foo/bar',
        ];
        $cache->put('foo/bar', $release);
        $cache->put('other/baz', $release);

        $this->assertSame(2, $cache->entryCount());
        $this->assertSame(2, $cache->purgeAll());
        $this->assertSame(0, $cache->entryCount());
        $this->assertFalse($cache->purge('foo/bar'));

        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }
    }
}