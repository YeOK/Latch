<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Auth;
use Latch\Core\PostFormatter;
use Latch\Support\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class SecurityRegressionTest extends TestCase
{
    public function testPostFormatterEscapesRawHtml(): void
    {
        $html = (new PostFormatter())->format('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPostFormatterBlocksJavascriptUrlsInMarkdownLinks(): void
    {
        $html = (new PostFormatter())->format('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('click', $html);
    }

    public function testPostFormatterQuoteDoesNotExecuteHtml(): void
    {
        $html = (new PostFormatter())->format(
            "[quote author=\"<img src=x onerror=alert(1)>\"]\nSafe body\n[/quote]",
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testWebhookGuardRejectsLoopbackTargets(): void
    {
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://127.0.0.1/hook'));
        $this->assertNotNull(OutboundUrlGuard::publicHttpsUrlError('https://[::1]/hook'));
    }

    public function testFounderUserIdIsProtectedConstant(): void
    {
        $this->assertSame(1, Auth::FOUNDER_USER_ID);
    }
}