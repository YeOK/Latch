<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardTest extends TestCase
{
    public function testAllowsPublicHttpsUrl(): void
    {
        $this->assertNull(OutboundUrlGuard::publicHttpsUrlError('https://1.1.1.1/hook'));
        $this->assertSame(
            'https://example.com/path',
            OutboundUrlGuard::normalizePublicHttpsUrl('https://example.com/path'),
        );
    }

    public function testRejectsHttpUrl(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('http://hooks.example.com/latch'));
    }

    public function testRejectsLocalhost(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://localhost/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://app.localhost/hook'));
    }

    public function testRejectsPrivateIpv4Literal(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://127.0.0.1/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://10.0.0.5/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://192.168.1.42/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://169.254.169.254/latest/meta-data'));
    }

    public function testRejectsIpv6LoopbackLiteral(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://[::1]/hook'));
    }

    public function testRejectsMdnsAndMetadataHosts(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://printer.local/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://metadata.google.internal/hook'));
    }

    public function testResolveRedirectLocationAllowsPublicAbsoluteTarget(): void
    {
        $this->assertSame(
            'https://8.8.8.8/asset',
            OutboundUrlGuard::resolveRedirectLocation(
                'https://1.1.1.1/start',
                'https://8.8.8.8/asset',
            ),
        );
    }

    public function testResolveRedirectLocationResolvesRelativePath(): void
    {
        $this->assertSame(
            'https://1.1.1.1/next/page',
            OutboundUrlGuard::resolveRedirectLocation(
                'https://1.1.1.1/start/page',
                '/next/page',
            ),
        );
    }

    public function testResolveRedirectLocationRejectsPrivateTarget(): void
    {
        $this->assertNull(OutboundUrlGuard::resolveRedirectLocation(
            'https://1.1.1.1/start',
            'https://127.0.0.1/secret',
        ));
        $this->assertNull(OutboundUrlGuard::resolveRedirectLocation(
            'https://1.1.1.1/start',
            'https://192.168.0.5/internal',
        ));
    }

    public function testResolveRedirectLocationRejectsNonHttpSchemes(): void
    {
        $this->assertNull(OutboundUrlGuard::resolveRedirectLocation(
            'https://1.1.1.1/start',
            'file:///etc/passwd',
        ));
    }
}