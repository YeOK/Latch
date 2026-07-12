<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\Logs\LogLineRedactor;
use PHPUnit\Framework\TestCase;

final class LogLineRedactorTest extends TestCase
{
    public function testRedactsPasswordQueryParam(): void
    {
        $redactor = new LogLineRedactor();
        $line = 'POST /login password=secret123&user=admin';

        $this->assertStringContainsString('password=[REDACTED]', $redactor->redact($line));
        $this->assertStringNotContainsString('secret123', $redactor->redact($line));
    }

    public function testRedactsJsonPasswordField(): void
    {
        $redactor = new LogLineRedactor();
        $line = '{"password":"hunter2","user":"admin"}';

        $this->assertSame('{"password":"[REDACTED]","user":"admin"}', $redactor->redact($line));
    }

    public function testRedactsAuthorizationBearer(): void
    {
        $redactor = new LogLineRedactor();
        $line = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig';

        $this->assertSame('Authorization: Bearer [REDACTED]', $redactor->redact($line));
    }

    public function testRedactsCookieHeader(): void
    {
        $redactor = new LogLineRedactor();
        $line = 'Cookie: latch_session=abc123; other=value';

        $this->assertSame('Cookie: [REDACTED]', $redactor->redact($line));
    }

    public function testRedactsTokenFields(): void
    {
        $redactor = new LogLineRedactor();
        $line = 'reset_token=abc&client_secret=def';

        $this->assertStringContainsString('reset_token=[REDACTED]', $redactor->redact($line));
        $this->assertStringContainsString('client_secret=[REDACTED]', $redactor->redact($line));
    }

    public function testMasksEmailsWhenEnabled(): void
    {
        $redactor = new LogLineRedactor(maskEmails: true);
        $line = 'Contact alice@example.com for help';

        $this->assertStringContainsString('a***@example.com', $redactor->redact($line));
        $this->assertStringNotContainsString('alice@example.com', $redactor->redact($line));
    }
}