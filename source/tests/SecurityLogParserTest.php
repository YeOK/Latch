<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\Logs\SecurityLogParser;
use PHPUnit\Framework\TestCase;

final class SecurityLogParserTest extends TestCase
{
    private SecurityLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SecurityLogParser();
    }

    public function testParsesValidSecurityLine(): void
    {
        $line = '{"ts":"2026-07-12T10:00:00+00:00","event":"login_fail","ip":"203.0.113.5","username":"admin"}';
        $parsed = $this->parser->parseLine($line);

        $this->assertNotNull($parsed);
        $this->assertFalse($parsed['parse_error']);
        $this->assertSame('login_fail', $parsed['event']);
        $this->assertSame('203.0.113.5', $parsed['ip']);
        $this->assertSame('admin', $parsed['username']);
    }

    public function testInvalidJsonSetsParseError(): void
    {
        $parsed = $this->parser->parseLine('not-json');

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['parse_error']);
    }

    public function testMatchesEventFilter(): void
    {
        $line = '{"ts":"2026-07-12T10:00:00+00:00","event":"login_fail","ip":"1.1.1.1","username":"bob"}';

        $this->assertTrue($this->parser->matchesLine($line, ['event' => 'login_fail']));
        $this->assertFalse($this->parser->matchesLine($line, ['event' => 'login_success']));
    }

    public function testMatchesUsernameCaseInsensitive(): void
    {
        $line = '{"ts":"2026-07-12T10:00:00+00:00","event":"login_fail","username":"Alice"}';

        $this->assertTrue($this->parser->matchesLine($line, ['username' => 'alice']));
        $this->assertFalse($this->parser->matchesLine($line, ['username' => 'bob']));
    }

    public function testMatchesTimeBounds(): void
    {
        $line = '{"ts":"2026-07-12T12:00:00+00:00","event":"login_fail"}';

        $this->assertTrue($this->parser->matchesLine($line, [
            'since' => '2026-07-12T00:00:00Z',
            'until' => '2026-07-12T23:59:59Z',
        ]));
        $this->assertFalse($this->parser->matchesLine($line, [
            'until' => '2026-07-11T23:59:59Z',
        ]));
    }
}