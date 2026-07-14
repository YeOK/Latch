<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-theme-registry-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/default', 0775, true);
        file_put_contents($this->root . '/default/theme.json', json_encode([
            'name' => 'Default Pack',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        mkdir($this->root . '/modern/assets/css', 0775, true);
        file_put_contents($this->root . '/modern/theme.json', json_encode([
            'name' => 'Modern Pack',
            'version' => '2.0.0',
            'branding' => [
                'theme_color_light' => '#0d9488',
                'theme_color_dark' => '#0f1419',
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInstalledListsThemeDirectories(): void
    {
        $registry = new ThemeRegistry($this->root);
        $installed = $registry->installed();

        $this->assertCount(2, $installed);
        $this->assertSame('default', $installed[0]['id']);
        $this->assertSame('Default Pack', $installed[0]['name']);
        $this->assertSame('modern', $installed[1]['id']);
    }

    public function testResolveRejectsInvalidIds(): void
    {
        $registry = new ThemeRegistry($this->root);

        $this->assertFalse($registry->isValid('../etc'));
        $this->assertFalse($registry->isValid('missing'));
        $this->assertSame('default', $registry->resolve('missing', 'default'));
        $this->assertSame('modern', $registry->resolve('modern', 'default'));
    }

    public function testAssetVersionSaltDiffersPerPack(): void
    {
        $this->assertNotSame(
            ThemeRegistry::assetVersionSalt('default'),
            ThemeRegistry::assetVersionSalt('modern'),
        );
    }

    public function testBrandingReadsManifestAndFallsBackToDefaults(): void
    {
        $registry = new ThemeRegistry($this->root);

        $this->assertSame([
            'theme_color_light' => '#2f6fed',
            'theme_color_dark' => '#1a212b',
        ], $registry->branding('default'));

        $this->assertSame([
            'theme_color_light' => '#0d9488',
            'theme_color_dark' => '#0f1419',
        ], $registry->branding('modern'));

        $this->assertSame([
            'theme_color_light' => '#2f6fed',
            'theme_color_dark' => '#1a212b',
        ], $registry->branding('missing'));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}