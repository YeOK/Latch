<?php

declare(strict_types=1);

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
}