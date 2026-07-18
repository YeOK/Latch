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
use Latch\Plugins\AvatarUrl\AvatarUrlValidator;
use Latch\Plugins\AvatarUrl\Settings;
use PHPUnit\Framework\TestCase;

final class AvatarUrlPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/avatar-url')) {
            $this->markTestSkipped('avatar-url plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/avatar-url';
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('avatar-url') . '\\';
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

    public function testValidatorAcceptsAllowedHttpsHost(): void
    {
        $settings = new Settings(['cdn.example.com', '*.githubusercontent.com'], true);
        $validator = new AvatarUrlValidator($settings);

        $ok = $validator->validate('https://cdn.example.com/me.png');
        $this->assertTrue($ok['ok']);
        $this->assertSame('https://cdn.example.com/me.png', $ok['url']);

        $ok2 = $validator->validate('https://raw.githubusercontent.com/user/repo/main/a.png');
        $this->assertTrue($ok2['ok']);
    }

    public function testValidatorRejectsDisallowedAndInsecure(): void
    {
        $settings = new Settings(['cdn.example.com'], true);
        $validator = new AvatarUrlValidator($settings);

        $this->assertFalse($validator->validate('http://cdn.example.com/a.png')['ok']);
        $this->assertFalse($validator->validate('https://evil.example.com/a.png')['ok']);
        $this->assertFalse($validator->validate('https://127.0.0.1/a.png')['ok']);
        $this->assertFalse($validator->validate('https://cdn.example.com/x.svg')['ok']);
    }

    public function testEmptyUrlClearsAvatar(): void
    {
        $settings = new Settings(['cdn.example.com'], true);
        $validator = new AvatarUrlValidator($settings);

        $ok = $validator->validate('');
        $this->assertTrue($ok['ok']);
        $this->assertSame('', $ok['url']);
    }
}
