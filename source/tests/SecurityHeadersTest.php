<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testRewriteHtmlNoncesReplacesAllNonceAttributes(): void
    {
        $old = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $new = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $html = '<script nonce="' . $old . '">x</script><script nonce="' . $old . '">y</script>';

        $rewritten = SecurityHeaders::rewriteHtmlNonces($html, $new);

        $this->assertStringNotContainsString($old, $rewritten);
        $this->assertSame(
            '<script nonce="' . $new . '">x</script><script nonce="' . $new . '">y</script>',
            $rewritten,
        );
    }

    public function testRewriteHtmlNoncesLeavesHtmlUntouchedForInvalidNonce(): void
    {
        $html = '<script nonce="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa">x</script>';

        $this->assertSame($html, SecurityHeaders::rewriteHtmlNonces($html, 'not-a-nonce'));
    }

    public function testContentSecurityPolicyFontSrcUsesValidSelfToken(): void
    {
        $nonce = 'a' . str_repeat('b', 31);
        $csp = SecurityHeaders::contentSecurityPolicy(
            scriptSrc: "'self' 'nonce-{$nonce}'",
            imgSrc: "'self' https://www.gravatar.com https://secure.gravatar.com data:",
            connectSrc: "'self'",
            frameSrc: 'https://challenges.cloudflare.com',
        );

        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringNotContainsString("font-src \\'self\\'", $csp);
    }
}