<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginStoragePermissions;
use PHPUnit\Framework\TestCase;

final class PluginStoragePermissionsTest extends TestCase
{
    public function testResolveWebUserPrefersOverride(): void
    {
        $this->assertSame('www-data', PluginStoragePermissions::resolveWebUser('www-data'));
    }

    public function testFixTargetsIncludesStoragePluginsAndCodePath(): void
    {
        $targets = PluginStoragePermissions::fixTargets('/var/lib/latch/storage', '/usr/share/latch/source/plugins', '/etc/latch/local.php');
        $labels = array_column($targets, 'label');

        $this->assertContains('storage/', $labels);
        $this->assertContains('storage/database', $labels);
        $this->assertContains('storage/cache/twig', $labels);
        $this->assertContains('storage/cache/pages', $labels);
        $this->assertContains('storage/cache/fragments', $labels);
        $this->assertContains('storage/cache/plugin-audits', $labels);
        $this->assertContains('storage/plugins', $labels);
        $this->assertContains('plugins/ (code)', $labels);
        $this->assertContains('config/local.php', $labels);
    }
}