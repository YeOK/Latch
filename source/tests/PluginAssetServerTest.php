<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\PluginAssetServer;
use PHPUnit\Framework\TestCase;

final class PluginAssetServerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-plugin-assets-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/forum-stats/assets', 0775, true);
        file_put_contents($this->root . '/forum-stats/assets/stats.css', '/* stats */');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/forum-stats/assets/stats.css');
        @rmdir($this->root . '/forum-stats/assets');
        @rmdir($this->root . '/forum-stats');
        @rmdir($this->root);
    }

    public function testRecognizesStaticPluginPaths(): void
    {
        $this->assertTrue(PluginAssetServer::isAssetPath('/plugin/forum-stats/stats.css'));
        $this->assertTrue(PluginAssetServer::isAssetPath('/plugin/member-signature/signature.css?v=1'));
        $this->assertFalse(PluginAssetServer::isAssetPath('/plugin/invite-only/admin'));
        $this->assertFalse(PluginAssetServer::isAssetPath('/plugin/git-release/widget.json'));
        $this->assertFalse(PluginAssetServer::isAssetPath('/assets/css/theme.css'));
    }

    public function testServesAssetsAndRejectsTraversal(): void
    {
        $built = PluginAssetServer::build($this->root, '/plugin/forum-stats/stats.css');
        $this->assertNotNull($built);
        $this->assertSame('text/css; charset=utf-8', $built['mime']);
        $this->assertSame('/* stats */', $built['body']);

        $this->assertNull(PluginAssetServer::build($this->root, '/plugin/forum-stats/../stats.css'));
        $this->assertNull(PluginAssetServer::build($this->root, '/plugin/missing/stats.css'));
    }
}
