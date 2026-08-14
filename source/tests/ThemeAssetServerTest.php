<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\ThemeAssetServer;
use Latch\Core\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeAssetServerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-theme-assets-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/default/assets/css', 0775, true);
        mkdir($this->root . '/default/assets/js', 0775, true);
        mkdir($this->root . '/modern/assets/css', 0775, true);
        file_put_contents($this->root . '/default/theme.json', '{"name":"Default"}');
        file_put_contents($this->root . '/modern/theme.json', '{"name":"Modern"}');
        file_put_contents($this->root . '/default/assets/css/theme.css', '/* default */');
        file_put_contents($this->root . '/default/assets/js/theme.js', '/* js */');
        file_put_contents($this->root . '/modern/assets/css/theme.css', '/* modern */');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testIsAssetPath(): void
    {
        $this->assertTrue(ThemeAssetServer::isAssetPath('/assets/css/theme.css'));
        $this->assertTrue(ThemeAssetServer::isAssetPath('/assets/'));
        $this->assertTrue(ThemeAssetServer::isAssetPath('assets/js/theme.js'));
        $this->assertFalse(ThemeAssetServer::isAssetPath('/'));
        $this->assertFalse(ThemeAssetServer::isAssetPath('/asset/css/theme.css'));
        $this->assertFalse(ThemeAssetServer::isAssetPath('/branding/logo'));
    }

    public function testServesDefaultFileAndRejectsTraversal(): void
    {
        $css = ThemeAssetServer::build($this->root, 'default', 'css/theme.css');
        $this->assertNotNull($css);
        $this->assertSame('text/css; charset=utf-8', $css['mime']);
        $this->assertSame('/* default */', $css['body']);

        $js = ThemeAssetServer::build($this->root, 'default', 'js/theme.js');
        $this->assertNotNull($js);
        $this->assertSame('application/javascript; charset=utf-8', $js['mime']);

        $this->assertNull(ThemeAssetServer::build($this->root, 'default', '../theme.json'));
        $this->assertNull(ThemeAssetServer::build($this->root, 'default', 'missing.css'));
        $this->assertNull(ThemeAssetServer::build($this->root, 'default', ''));
    }

    public function testChildThemeCssIsAppendedAfterDefault(): void
    {
        $built = ThemeAssetServer::build($this->root, 'modern', 'css/theme.css');
        $this->assertNotNull($built);
        $this->assertSame("/* default */\n/* modern */", $built['body']);
    }

    public function testNonCssChildAssetsFallBackToDefault(): void
    {
        $built = ThemeAssetServer::build($this->root, 'modern', 'js/theme.js');
        $this->assertNotNull($built);
        $this->assertSame('/* js */', $built['body']);
    }

    public function testActiveThemeReadsSettingsThenFallsBack(): void
    {
        $configDir = $this->root . '/config';
        mkdir($configDir, 0775, true);
        $dbPath = $this->root . '/latch.sqlite';
        $db = new Database($dbPath);
        $db->pdo()->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);');
        $db->pdo()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')
            ->execute([ThemeRegistry::SETTING_ACTIVE, 'modern']);

        file_put_contents($configDir . '/default.php', '<?php return [
            "database" => ["path" => ' . var_export($dbPath, true) . '],
            "paths" => ["themes" => ' . var_export($this->root, true) . '],
            "theme" => ["active" => "default"],
        ];');

        $config = new Config($configDir);
        $this->assertSame('modern', ThemeAssetServer::activeTheme($config));

        $db->pdo()->exec("UPDATE settings SET value = 'missing-pack' WHERE key = 'active_theme'");
        $this->assertSame('default', ThemeAssetServer::activeTheme($config));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
