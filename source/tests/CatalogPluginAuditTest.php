<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Enable-gate scan of every plugin directory in the sibling Latch-plugins catalog.
 */
final class CatalogPluginAuditTest extends TestCase
{
    public function testEveryCatalogPluginIsEnableAllowed(): void
    {
        $root = CatalogPath::root();
        $auditor = new PluginAuditor(
            dirname(__DIR__),
            dirname(__DIR__) . '/plugins',
            dirname(__DIR__) . '/storage',
        );

        $dirs = glob($root . '/*/plugin.json') ?: [];
        $this->assertNotSame([], $dirs, 'Latch-plugins catalog has no plugin.json trees');

        foreach ($dirs as $manifest) {
            $dir = dirname($manifest);
            $slug = basename($dir);
            $report = $auditor->auditPath($dir);
            $this->assertTrue(
                $report->enableAllowed(),
                "Catalog plugin {$slug} failed plugin-audit:\n" . $report->toHuman(),
            );
        }
    }
}
