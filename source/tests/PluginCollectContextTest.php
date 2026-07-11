<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Application;
use Latch\Core\Plugins\PluginCollectContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginCollectContextTest extends TestCase
{
    public function testApplicationImplementsPluginCollectContext(): void
    {
        $ref = new ReflectionClass(Application::class);

        $this->assertTrue($ref->implementsInterface(PluginCollectContext::class));

        foreach ([
            'guestFragmentCacheEnabled',
            'cache',
            'cacheTtlSeconds',
            'resolvedLocale',
            'cspNonce',
        ] as $method) {
            $this->assertTrue(
                $ref->hasMethod($method) && $ref->getMethod($method)->isPublic(),
                "Application::{$method}() must be public for PluginCollectContext",
            );
        }
    }
}