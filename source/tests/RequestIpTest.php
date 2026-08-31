<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestIpTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testIgnoresSpoofedCfConnectingIpFromPublicClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_CF_RAY'] = 'spoof';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';

        $request = new Request(new Config(dirname(__DIR__) . '/config'));

        $this->assertSame('8.8.8.8', $request->ip());
    }

    public function testTrustsCfConnectingIpFromLoopbackTunnel(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_CF_RAY'] = 'abc123-LHR';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';

        $request = new Request(new Config(dirname(__DIR__) . '/config'));

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function testTrustsCfConnectingIpFromCloudflareRange(): void
    {
        $_SERVER['REMOTE_ADDR'] = '162.158.1.1';
        $_SERVER['HTTP_CF_RAY'] = 'abc123-LHR';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';

        $request = new Request(new Config(dirname(__DIR__) . '/config'));

        $this->assertSame('198.51.100.4', $request->ip());
    }

    public function testRequiresCfRayEvenFromLoopback(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';

        $request = new Request(new Config(dirname(__DIR__) . '/config'));

        $this->assertSame('127.0.0.1', $request->ip());
    }
}
