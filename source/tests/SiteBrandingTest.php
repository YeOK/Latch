<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\SiteBranding;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class SiteBrandingTest extends TestCase
{
    private string $storageRoot;
    private string $dbPath;
    private Database $db;
    private SettingRepository $settings;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/latch-branding-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot, 0775, true);

        $this->dbPath = $this->storageRoot . '/test.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);',
        );
        $this->settings = new SettingRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->storageRoot);
    }

    public function testDefaultModeLatchWhenSiteNameIsLatch(): void
    {
        $this->settings->set('site_name', 'Latch');
        $branding = $this->branding();

        $this->assertSame(SiteBranding::MODE_LATCH, $branding->mode());
        $this->assertTrue($branding->usesLatchBuiltinMark());
        $this->assertNull($branding->logoUrl());
    }

    public function testDefaultModeCustomForOtherSiteNames(): void
    {
        $this->settings->set('site_name', 'My Forum');
        $branding = $this->branding();

        $this->assertSame(SiteBranding::MODE_CUSTOM, $branding->mode());
        $this->assertSame('/assets/img/latch-logo.svg', $branding->logoUrl());
    }

    public function testTextOnlyHidesMark(): void
    {
        $this->settings->set('site_name', 'My Forum');
        $branding = $this->branding();
        $this->assertNull($branding->setMode(SiteBranding::MODE_TEXT_ONLY));

        $this->assertFalse($branding->showMark());
        $this->assertNull($branding->logoUrl());
    }

    public function testPersistSvgLogo(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $branding = $this->branding();
        $this->settings->set('brand_mode', SiteBranding::MODE_CUSTOM);

        $error = $branding->persistLogo($svg, 'svg');

        $this->assertNull($error);
        $this->assertTrue($branding->hasUploadedLogo());
        $this->assertStringStartsWith('/branding/logo?v=', (string) $branding->logoUrl());
        $this->assertSame('image/svg+xml', $branding->mimeForServe());
        $this->assertNotNull($branding->faviconUrl());
    }

    public function testRejectSvgWithScript(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $branding = $this->branding();
        $error = $branding->persistLogo($svg, 'svg');

        $this->assertNotNull($error);
        $this->assertFalse($branding->hasUploadedLogo());
    }

    public function testRemoveLogoClearsExt(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>';
        $dir = $this->storageRoot . '/branding';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/logo.svg', $svg);
        $this->settings->set('brand_logo_ext', 'svg');

        $branding = $this->branding();
        $branding->removeLogo();

        $this->assertFalse($branding->hasUploadedLogo());
        $this->assertSame('', (string) $this->settings->get('brand_logo_ext', ''));
    }

    public function testInvalidModeRejected(): void
    {
        $branding = $this->branding();
        $this->assertNotNull($branding->setMode('hacked'));
    }

    private function branding(): SiteBranding
    {
        return new SiteBranding($this->settings, $this->storageRoot);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}