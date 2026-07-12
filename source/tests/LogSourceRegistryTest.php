<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Support\Logs\LogSourceRegistry;
use PHPUnit\Framework\TestCase;

final class LogSourceRegistryTest extends TestCase
{
    private string $root;
    private string $configDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-log-registry-' . bin2hex(random_bytes(4));
        $this->configDir = $this->root . '/config';
        mkdir($this->configDir, 0700, true);
        mkdir($this->root . '/storage/logs', 0750, true);
        mkdir($this->root . '/config', 0700, true);
        mkdir($this->root . '/storage/database', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testBuiltInSecuritySourceMissingOnFreshInstall(): void
    {
        $registry = $this->registry([]);
        $source = $registry->getSource('latch.security');

        $this->assertNotNull($source);
        $this->assertSame('missing', $source['status']);
        $this->assertSame('json_lines', $source['format']);
    }

    public function testDenylistBlocksEtcPasswd(): void
    {
        $registry = $this->registry([]);
        $this->assertTrue($registry->isDeniedPath('/etc/passwd'));
    }

    public function testDenylistBlocksConfigLocalPhp(): void
    {
        $registry = $this->registry([]);
        $path = $this->root . '/config/local.php';
        touch($path);

        $this->assertTrue($registry->isDeniedPath($path));
    }

    public function testDenylistBlocksStoragePluginsEvenWithBroadAllowedRoot(): void
    {
        $pluginDir = $this->root . '/storage/plugins/demo';
        mkdir($pluginDir, 0750, true);
        $settings = $pluginDir . '/settings.json';
        file_put_contents($settings, '{"secret":"x"}');

        $registry = $this->registry([
            'logs' => [
                'server_logs_enabled' => true,
                'allowed_roots' => [$this->root . '/storage'],
                'sources' => [
                    [
                        'id' => 'plugin.settings',
                        'label' => 'Plugin settings',
                        'group' => 'Other',
                        'path' => $settings,
                        'format' => 'text',
                    ],
                ],
            ],
        ]);

        $source = $registry->getSource('plugin.settings');
        $this->assertNotNull($source);
        $this->assertSame('denied', $source['status']);
    }

    public function testServerSourceUnderVarLogAllowed(): void
    {
        $varLog = $this->root . '/var/log';
        mkdir($varLog, 0750, true);
        $access = $varLog . '/latch-access.log';
        file_put_contents($access, "127.0.0.1 - -\n");

        $registry = $this->registry([
            'logs' => [
                'server_logs_enabled' => true,
                'allowed_roots' => [$varLog],
                'sources' => [
                    [
                        'id' => 'httpd.access',
                        'label' => 'Apache access',
                        'group' => 'Web server',
                        'path' => $access,
                        'format' => 'text',
                    ],
                ],
            ],
        ]);

        $source = $registry->getSource('httpd.access');
        $this->assertNotNull($source);
        $this->assertSame('readable', $source['status']);
    }

    public function testServerSourcesIgnoredWhenDisabled(): void
    {
        $registry = $this->registry([
            'logs' => [
                'server_logs_enabled' => false,
                'sources' => [
                    [
                        'id' => 'httpd.access',
                        'label' => 'Apache access',
                        'group' => 'Web server',
                        'path' => '/var/log/httpd/latch-access.log',
                        'format' => 'text',
                    ],
                ],
            ],
        ]);

        $this->assertNull($registry->getSource('httpd.access'));
        $this->assertCount(2, $registry->listSources());
    }

    /**
     * @param array<string, mixed> $localOverrides
     */
    private function registry(array $localOverrides): LogSourceRegistry
    {
        $default = require dirname(__DIR__) . '/config/default.php';
        $default['paths']['storage'] = $this->root . '/storage';
        $default['database']['path'] = $this->root . '/storage/database/latch.sqlite';

        file_put_contents($this->configDir . '/default.php', '<?php return ' . var_export($default, true) . ';');
        if ($localOverrides !== []) {
            file_put_contents($this->configDir . '/local.php', '<?php return ' . var_export($localOverrides, true) . ';');
        }

        return LogSourceRegistry::fromConfig(new Config($this->configDir));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}