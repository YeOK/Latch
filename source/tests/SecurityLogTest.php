<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\SecurityLog;
use PHPUnit\Framework\TestCase;

final class SecurityLogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/latch-seclog-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testRegistrationBlockReasonLandsInMeta(): void
    {
        $log = new SecurityLog($this->path);
        $log->log('registration_blocked', [
            'ip' => '203.0.113.9',
            'username' => 'botname',
            'meta' => ['reason' => 'honeypot'],
        ]);

        $row = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame('registration_blocked', $row['event']);
        $this->assertSame('203.0.113.9', $row['ip']);
        $this->assertSame('botname', $row['username']);
        $this->assertSame('honeypot', $row['meta']['reason']);
    }

    public function testLooseContextKeysAreFoldedIntoMeta(): void
    {
        $log = new SecurityLog($this->path);
        $log->log('registration_blocked', [
            'ip' => '198.51.100.2',
            'username' => 'x',
            'reason' => 'turnstile',
        ]);

        $row = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame('turnstile', $row['meta']['reason']);
    }
}
