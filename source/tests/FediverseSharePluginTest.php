<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginManifest;
use Latch\Plugins\FediverseShare\Settings;
use Latch\Plugins\FediverseShare\SharePanel;
use Latch\Plugins\FediverseShare\ShareUrlBuilder;
use PHPUnit\Framework\TestCase;

final class FediverseSharePluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/fediverse-share')) {
            $this->markTestSkipped('fediverse-share plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/fediverse-share';
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('fediverse-share') . '\\';
        $baseDir = $manifest->pluginDir . '/src/';

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

        $this->assertTrue($report->enableAllowed(), $report->toHuman());
    }

    public function testNormalizeInstance(): void
    {
        $this->assertSame('mastodon.social', ShareUrlBuilder::normalizeInstance('https://Mastodon.Social/foo'));
        $this->assertSame('fosstodon.org', ShareUrlBuilder::normalizeInstance('fosstodon.org/'));
        $this->assertNull(ShareUrlBuilder::normalizeInstance(''));
        $this->assertNull(ShareUrlBuilder::normalizeInstance('not a host!!!'));
        $this->assertNull(ShareUrlBuilder::normalizeInstance('http://192.168.1.1'));
    }

    public function testShareUrlsAndTemplate(): void
    {
        $text = ShareUrlBuilder::formatShareText("{title}\n{url}", [
            'title' => 'Hello',
            'url' => 'https://forum.example.com/topic/1',
            'site' => 'Forum',
        ]);
        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('https://forum.example.com/topic/1', $text);

        $m = ShareUrlBuilder::mastodonShareUrl('mastodon.social', $text);
        $this->assertStringStartsWith('https://mastodon.social/share?text=', $m);

        $k = ShareUrlBuilder::misskeyShareUrl('misskey.io', $text);
        $this->assertStringStartsWith('https://misskey.io/share?text=', $k);

        $this->assertSame(
            'https://forum.example.com/topic/my-slug',
            ShareUrlBuilder::topicUrl('https://forum.example.com/', 9, 'my-slug'),
        );
    }

    public function testPanelRendersEscapedMarkup(): void
    {
        $settings = new Settings(
            enabled: true,
            defaultInstance: 'mastodon.social',
            shareTemplate: "{title}\n{url}",
            presetInstances: ['mastodon.social'],
            showMastodon: true,
            showMisskey: true,
            showCopyLink: true,
            showWebShare: true,
        );
        $panel = new SharePanel($settings);
        $html = $panel->render('My <Site>', 'https://forum.example.com', [
            'id' => 42,
            'title' => 'Hello <script>',
            'slug' => 'hello',
        ]);

        $this->assertStringContainsString('data-latch-fedi-share', $html);
        $this->assertStringContainsString('data-fedi-action="mastodon"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Hello &lt;script&gt;', $html);
    }

    public function testPanelHiddenWhenDisabled(): void
    {
        $settings = new Settings(
            enabled: false,
            defaultInstance: '',
            shareTemplate: '{url}',
            presetInstances: Settings::DEFAULT_PRESETS,
            showMastodon: true,
            showMisskey: true,
            showCopyLink: true,
            showWebShare: true,
        );
        $html = (new SharePanel($settings))->render('S', 'https://x.test', ['id' => 1, 'title' => 'T']);
        $this->assertSame('', $html);
    }
}
