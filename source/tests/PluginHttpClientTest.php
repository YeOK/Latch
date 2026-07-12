<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginHttpClient;
use PHPUnit\Framework\TestCase;

final class PluginHttpClientTest extends TestCase
{
    public function testStatusFromHeadersUsesFinalHop(): void
    {
        $headers = [
            'HTTP/1.1 302 Found',
            'Location: https://release-assets.githubusercontent.com/foo.zip',
            'HTTP/1.1 200 OK',
            'Content-Type: application/octet-stream',
        ];

        $this->assertSame(200, PluginHttpClient::statusFromHeaders($headers));
    }

    public function testDownloadsGithubReleaseZipFollowingRedirect(): void
    {
        $client = new PluginHttpClient();
        $url = 'https://github.com/YeOK/Latch-plugins/releases/download/v1.0.1/slack-notify-1.0.0.zip';

        $response = $client->request('GET', $url);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('PK', $response['body']);
    }
}