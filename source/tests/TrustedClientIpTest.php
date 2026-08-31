<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\TrustedClientIp;
use PHPUnit\Framework\TestCase;

final class TrustedClientIpTest extends TestCase
{
    public function testLoopbackIsTrusted(): void
    {
        $this->assertTrue(TrustedClientIp::isTrustedProxy('127.0.0.1'));
        $this->assertTrue(TrustedClientIp::isTrustedProxy('::1'));
    }

    public function testPublicResolverIsNotTrusted(): void
    {
        $this->assertFalse(TrustedClientIp::isTrustedProxy('8.8.8.8'));
        $this->assertFalse(TrustedClientIp::isTrustedProxy('1.1.1.1'));
    }

    public function testCloudflareAnycastIsTrusted(): void
    {
        $this->assertTrue(TrustedClientIp::isTrustedProxy('104.16.0.1'));
        $this->assertTrue(TrustedClientIp::isTrustedProxy('2606:4700::1'));
    }

    public function testMappedIpv4LoopbackIsTrusted(): void
    {
        $this->assertTrue(TrustedClientIp::isTrustedProxy('::ffff:127.0.0.1'));
    }

    public function testExtraCidr(): void
    {
        $this->assertFalse(TrustedClientIp::isTrustedProxy('10.0.0.5'));
        $this->assertTrue(TrustedClientIp::isTrustedProxy('10.0.0.5', ['10.0.0.0/8']));
    }
}
