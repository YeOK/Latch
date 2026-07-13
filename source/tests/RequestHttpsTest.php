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

final class RequestHttpsTest extends TestCase
{
    public function testRejectsSpoofedForwardedProtoWithoutCfRay(): void
    {
        $config = new Config(dirname(__DIR__) . '/config');
        $server = [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        $this->assertFalse(Request::detectHttps($config, $server));
    }

    public function testAcceptsForwardedProtoWithCfRay(): void
    {
        $config = new Config(dirname(__DIR__) . '/config');
        $server = [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_CF_RAY' => 'abc123-LHR',
        ];

        $this->assertTrue(Request::detectHttps($config, $server));
    }

    public function testQueryReadsGetWithoutPost(): void
    {
        $_GET['tab'] = 'catalog';
        $_POST['tab'] = 'installed';

        $request = new Request();

        $this->assertSame('catalog', $request->query('tab'));
        $this->assertSame('installed', $request->input('tab'));
        $this->assertSame('installed', $request->query('missing', 'installed'));

        unset($_GET['tab'], $_POST['tab']);
    }
}