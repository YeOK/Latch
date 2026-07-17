<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\BoardIcons\BoardIconRegistry;
use Latch\Core\Config;
use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginManifest;
use Latch\Plugins\BoardIconPack\IconPack;
use PHPUnit\Framework\TestCase;

final class BoardIconPackPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/board-icon-pack')) {
            $this->markTestSkipped('board-icon-pack plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/board-icon-pack';
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('board-icon-pack') . '\\';
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

    public function testRegistersIconsAndKeywords(): void
    {
        $themes = dirname(__DIR__) . '/themes';
        $tmp = sys_get_temp_dir() . '/latch-board-icon-cfg-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        file_put_contents($tmp . '/default.php', '<?php return ["paths" => ["themes" => ' . var_export($themes, true) . ']];');

        $registry = new BoardIconRegistry(new Config($tmp));
        $before = count($registry->keys());

        $count = (new IconPack($this->pluginDir))->register($registry);

        $this->assertGreaterThanOrEqual(16, $count);
        $this->assertTrue($registry->has('server'));
        $this->assertTrue($registry->has('open-source'));
        $this->assertStringStartsWith('<svg', $registry->svg('security'));
        $this->assertSame('server', $registry->suggestKey('Hosting Ops', 'server-ops'));
        $this->assertSame('books', $registry->suggestKey('Documentation', 'docs-wiki'));
        $this->assertGreaterThan($before, count($registry->keys()));

        array_map('unlink', glob($tmp . '/*') ?: []);
        @rmdir($tmp);
    }
}
