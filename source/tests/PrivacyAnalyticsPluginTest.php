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
use Latch\Plugins\PrivacyAnalytics\AnalyticsSnippet;
use Latch\Plugins\PrivacyAnalytics\HostValidator;
use Latch\Plugins\PrivacyAnalytics\Settings;
use PHPUnit\Framework\TestCase;

final class PrivacyAnalyticsPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->pluginDir = CatalogPath::plugin('privacy-analytics');
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('privacy-analytics') . '\\';
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

    public function testPlausibleSnippetRendersConfiguredDomain(): void
    {
        $settings = new Settings(
            Settings::PROVIDER_PLAUSIBLE,
            'latch.network',
            'plausible.io',
            '',
            '',
            true,
        );
        $snippet = new AnalyticsSnippet($settings);

        $html = $snippet->renderHead('abc123nonce');

        $this->assertStringContainsString('https://plausible.io/js/script.js', $html);
        $this->assertStringContainsString('data-domain="latch.network"', $html);
        $this->assertStringContainsString('nonce="abc123nonce"', $html);
        $this->assertSame('plausible.io', $snippet->cspScriptHost());
    }

    public function testMatomoSnippetRendersTracker(): void
    {
        $settings = new Settings(
            Settings::PROVIDER_MATOMO,
            '',
            'plausible.io',
            'https://analytics.example.com',
            '3',
            false,
        );
        $snippet = new AnalyticsSnippet($settings);

        $html = $snippet->renderHead('nonce');

        $this->assertStringContainsString('trackPageView', $html);
        $this->assertStringContainsString('https://analytics.example.com/matomo.js', $html);
        $this->assertStringContainsString('setSiteId","3"', $html);
        $this->assertSame('analytics.example.com', $snippet->cspScriptHost());
    }

    public function testUnconfiguredPlausibleReturnsEmptySnippet(): void
    {
        $settings = new Settings(Settings::PROVIDER_PLAUSIBLE, '', 'plausible.io', '', '', true);
        $snippet = new AnalyticsSnippet($settings);

        $this->assertFalse($snippet->isConfigured());
        $this->assertSame('', $snippet->renderHead('nonce'));
    }

    public function testHostValidatorRejectsBareIp(): void
    {
        $this->assertNull(HostValidator::httpsBaseUrl('https://127.0.0.1/'));
        $this->assertFalse(HostValidator::isValidDomain('192.168.1.1'));
    }
}