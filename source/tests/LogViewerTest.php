<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Support\Logs\LogViewer;
use Latch\Support\Logs\LogViewerException;
use PHPUnit\Framework\TestCase;

final class LogViewerTest extends TestCase
{
    private string $root;
    private string $configDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-log-viewer-' . bin2hex(random_bytes(4));
        $this->configDir = $this->root . '/config';
        mkdir($this->configDir, 0700, true);
        mkdir($this->root . '/storage/logs', 0750, true);
        mkdir($this->root . '/storage/database', 0750, true);

        $default = require dirname(__DIR__) . '/config/default.php';
        $default['paths']['storage'] = $this->root . '/storage';
        $default['database']['path'] = $this->root . '/storage/database/latch.sqlite';
        file_put_contents($this->configDir . '/default.php', '<?php return ' . var_export($default, true) . ';');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testParseFiltersFromHttpParams(): void
    {
        $viewer = LogViewer::fromConfig(new Config($this->configDir));
        $parsed = $viewer->parseRequestFilters([
            'source' => 'latch.security',
            'event' => 'login_fail',
            'limit' => '50',
        ]);

        $this->assertSame('latch.security', $parsed['source']);
        $this->assertSame(50, $parsed['limit']);
        $this->assertSame('login_fail', $parsed['filters']['event']);
    }

    public function testParseFiltersFromCliArgv(): void
    {
        $viewer = LogViewer::fromConfig(new Config($this->configDir));
        $parsed = $viewer->parseRequestFilters([
            '--source=latch.security',
            '--ip=203.0.113.5',
            '--lines=100',
        ]);

        $this->assertSame('latch.security', $parsed['source']);
        $this->assertSame(100, $parsed['limit']);
        $this->assertSame('203.0.113.5', $parsed['filters']['ip']);
    }

    public function testTailMissingBuiltInSourceReturnsEmpty(): void
    {
        $viewer = LogViewer::fromConfig(new Config($this->configDir));
        $result = $viewer->tail('latch.security', 10, null, null);

        $this->assertSame([], $result['lines']);
        $this->assertSame('missing', $result['source']['status']);
    }

    public function testTailAppliesRedactionAndParsing(): void
    {
        $path = $this->root . '/storage/logs/security.log';
        file_put_contents(
            $path,
            "{\"ts\":\"2026-07-12T10:00:00+00:00\",\"event\":\"login_fail\",\"ip\":\"1.1.1.1\",\"username\":\"admin\",\"password\":\"secret\"}\n",
        );

        $viewer = LogViewer::fromConfig(new Config($this->configDir));
        $source = $viewer->registry()->getSource('latch.security');
        $this->assertSame('readable', $source['status'] ?? null);

        $result = $viewer->tail('latch.security', 10, null, null, ['event' => 'login_fail']);

        $this->assertCount(1, $result['lines']);
        $this->assertStringContainsString('"password":"[REDACTED]"', $result['lines'][0]);
        $this->assertSame('login_fail', $result['parsed'][0]['event'] ?? null);
    }

    public function testRejectsUnknownSource(): void
    {
        $viewer = LogViewer::fromConfig(new Config($this->configDir));

        $this->expectException(LogViewerException::class);
        $viewer->parseRequestFilters(['source' => 'unknown.source']);
    }

    public function testFormatCliLineAppliesFilterAndRedaction(): void
    {
        $path = $this->root . '/storage/logs/security.log';
        file_put_contents(
            $path,
            "{\"ts\":\"2026-07-12T10:00:00+00:00\",\"event\":\"login_fail\",\"password\":\"secret\"}\n"
            . "{\"ts\":\"2026-07-12T10:01:00+00:00\",\"event\":\"login_success\",\"password\":\"secret\"}\n",
        );

        $viewer = LogViewer::fromConfig(new Config($this->configDir));
        $line = $viewer->formatCliLine('latch.security', '{"event":"login_fail","password":"secret"}', ['event' => 'login_fail']);

        $this->assertNotNull($line);
        $this->assertStringContainsString('[REDACTED]', $line);
        $this->assertNull($viewer->formatCliLine('latch.security', '{"event":"login_success"}', ['event' => 'login_fail']));
    }

    private function removeTree(string $dir): void
    {
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