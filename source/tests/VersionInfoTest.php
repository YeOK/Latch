<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Support\VersionInfo;
use PHPUnit\Framework\TestCase;

final class VersionInfoTest extends TestCase
{
    public function testCompareStatus(): void
    {
        $this->assertSame(VersionInfo::STATUS_CURRENT, VersionInfo::compareStatus('0.3.0.8', '0.3.0.8'));
        $this->assertSame(VersionInfo::STATUS_BEHIND, VersionInfo::compareStatus('0.3.0.3', '0.3.0.8'));
        $this->assertSame(VersionInfo::STATUS_AHEAD, VersionInfo::compareStatus('0.3.0.9', '0.3.0.8'));
        $this->assertSame(VersionInfo::STATUS_UNKNOWN, VersionInfo::compareStatus('0.3.0.3', null));
    }

    public function testReadVersionFile(): void
    {
        $dir = sys_get_temp_dir() . '/latch-version-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/VERSION', "0.3.0.8\n");

        $this->assertSame('0.3.0.8', VersionInfo::readVersionFile($dir));

        @unlink($dir . '/VERSION');
        @rmdir($dir);
    }

    public function testSnapshotUsesInstalledAndTreeVersions(): void
    {
        $root = dirname(__DIR__);
        $repoRoot = dirname($root);
        $config = new Config($root . '/config');

        $snapshot = VersionInfo::snapshot($config, $root);

        $this->assertSame((string) $config->get('app.version'), $snapshot['installed']);
        $this->assertSame(VersionInfo::readVersionFile($repoRoot), $snapshot['tree']);
        $this->assertContains($snapshot['status'], [
            VersionInfo::STATUS_CURRENT,
            VersionInfo::STATUS_BEHIND,
            VersionInfo::STATUS_AHEAD,
            VersionInfo::STATUS_UNKNOWN,
        ]);
    }
}