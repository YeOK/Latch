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
use Latch\Plugins\LinkPreview\HttpTransport;
use Latch\Plugins\LinkPreview\SafeUrl;
use PHPUnit\Framework\TestCase;

final class LinkPreviewPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->pluginDir = CatalogPath::plugin('link-preview');
        $this->registerAutoloader();
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testManifestDeclaresNetworkPermission(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);

        $this->assertTrue($manifest->permissions['network'] ?? false);
    }

    public function testSafeUrlRejectsPrivateTargets(): void
    {
        $this->assertNull(SafeUrl::normalize('https://127.0.0.1/page'));
        $this->assertNull(SafeUrl::normalize('https://192.168.1.1/page'));
        $this->assertNull(SafeUrl::normalize('https://[::1]/page'));
        $this->assertNull(SafeUrl::normalize('http://example.com/page'));
        $this->assertSame('https://1.1.1.1/page', SafeUrl::normalize('https://1.1.1.1/page'));
    }

    public function testHttpTransportBlocksRedirectToPrivateTarget(): void
    {
        $calls = [];
        $transport = new HttpTransport(5, static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
        ) use (&$calls): array {
            $calls[] = $url;

            if (count($calls) === 1) {
                return [
                    'status' => 302,
                    'headers' => ['Location' => 'https://127.0.0.1/internal'],
                    'body' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '<html></html>',
            ];
        });

        $this->assertNull($transport->get('https://1.1.1.1/public'));
        $this->assertSame(['https://1.1.1.1/public'], $calls);
    }

    public function testEmbedJsBindsEachPlayButtonToItsContainer(): void
    {
        $js = file_get_contents($this->pluginDir . '/assets/embed.js');
        $this->assertIsString($js);

        $this->assertStringContainsString(
            "mountEmbed(btn.closest('.link-embed'), true)",
            $js,
            'Play handlers must resolve the embed from the clicked button, not a shared loop variable',
        );
        $this->assertStringNotContainsString(
            'mountEmbed(el, true)',
            $js,
        );
    }

    public function testHttpTransportFollowsSafeRedirect(): void
    {
        $calls = [];
        $transport = new HttpTransport(5, static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
        ) use (&$calls): array {
            $calls[] = $url;

            if (count($calls) === 1) {
                return [
                    'status' => 302,
                    'headers' => ['Location' => '/final'],
                    'body' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '<html>ok</html>',
            ];
        });

        $this->assertSame('<html>ok</html>', $transport->get('https://1.1.1.1/start'));
        $this->assertSame([
            'https://1.1.1.1/start',
            'https://1.1.1.1/final',
        ], $calls);
    }

    private function registerAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\LinkPreview\\';
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

        $registered = true;
    }
}