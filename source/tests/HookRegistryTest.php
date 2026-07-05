<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\HookRegistry;
use PHPUnit\Framework\TestCase;

final class HookRegistryTest extends TestCase
{
    public function testCollectPreservesAssociativeAdminMenuItem(): void
    {
        $registry = new HookRegistry();
        $registry->add(HookName::ADMIN_MENU, static fn (): array => [
            'label' => 'Import markdown',
            'href' => '/plugin/md-import',
            'match' => '/plugin/md-import',
        ]);

        $items = $registry->collect(HookName::ADMIN_MENU);

        $this->assertCount(1, $items);
        $this->assertSame('Import markdown', $items[0]['label']);
        $this->assertSame('/plugin/md-import', $items[0]['href']);
    }
}