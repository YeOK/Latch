<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Support\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardTest extends TestCase
{
    public function testAllowsPublicHttpsUrl(): void
    {
        $this->assertNull(OutboundUrlGuard::publicHttpsUrlError('https://1.1.1.1/hook'));
    }

    public function testRejectsHttpUrl(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('http://hooks.example.com/latch'));
    }

    public function testRejectsLocalhost(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://localhost/hook'));
    }

    public function testRejectsPrivateIpv4Literal(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://127.0.0.1/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://10.0.0.5/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://169.254.169.254/latest/meta-data'));
    }
}