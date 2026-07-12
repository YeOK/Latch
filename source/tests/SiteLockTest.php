<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SiteLock;
use PHPUnit\Framework\TestCase;

final class SiteLockTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/latch-site-lock-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        SiteLock::disable($this->storage);
        if (is_dir($this->storage)) {
            @rmdir($this->storage);
        }
    }

    public function testEnableAndDisable(): void
    {
        $this->assertFalse(SiteLock::isLocked($this->storage));

        $result = SiteLock::enable($this->storage, 'Updating', 'tester');
        $this->assertTrue(SiteLock::isLocked($this->storage));
        $this->assertSame(32, strlen($result['unlock_token']));

        $state = SiteLock::read($this->storage);
        $this->assertNotNull($state);
        $this->assertSame('Updating', $state['message']);
        $this->assertSame('tester', $state['enabled_by']);
        $this->assertSame($result['unlock_token'], $state['unlock_token']);

        $this->assertSame('disabled', SiteLock::disable($this->storage));
        $this->assertFalse(SiteLock::isLocked($this->storage));
        $this->assertSame('not_locked', SiteLock::disable($this->storage));
    }

    public function testExemptPaths(): void
    {
        $this->assertTrue(SiteLock::isExemptWebPath('/maintenance/unlock'));
        $this->assertTrue(SiteLock::isExemptWebPath('/admin/site-lock/enabled'));
        $this->assertTrue(SiteLock::isExemptWebPath('/assets/css/theme.css'));
        $this->assertTrue(SiteLock::isExemptWebPath('/branding/logo'));
        $this->assertFalse(SiteLock::isExemptWebPath('/api/v1/boards'));
        $this->assertFalse(SiteLock::isExemptWebPath('/health'));
    }
}