<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\Logs\LogFileReader;
use PHPUnit\Framework\TestCase;

final class LogFileReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/latch-log-reader-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testTailsMostRecentLines(): void
    {
        $path = $this->tmpDir . '/app.log';
        file_put_contents($path, "line1\nline2\nline3\n");

        $reader = new LogFileReader();
        $result = $reader->tail($path, 2);

        $this->assertSame(['line2', 'line3'], $result['lines']);
        $this->assertNull($result['next_cursor']);
        $this->assertFalse($result['rotated']);
    }

    public function testLoadOlderUsesCursor(): void
    {
        $path = $this->tmpDir . '/paginated.log';
        $lines = [];
        for ($i = 1; $i <= 200; $i++) {
            $lines[] = 'entry-' . $i;
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        $reader = new LogFileReader(tailWindowBytes: 400);
        $first = $reader->tail($path, 5);
        $this->assertCount(5, $first['lines']);
        $this->assertSame('entry-' . (200 - 4), $first['lines'][0]);
        $this->assertSame('entry-200', $first['lines'][4]);
        $this->assertNotNull($first['next_cursor']);

        $second = $reader->tail(
            $path,
            5,
            $first['next_cursor'],
            $first['fingerprint'],
        );
        $this->assertCount(5, $second['lines']);
        $oldestFirst = (int) substr($first['lines'][0], 6);
        $oldestSecond = (int) substr($second['lines'][0], 6);
        $this->assertLessThan($oldestFirst, $oldestSecond);
        $this->assertFalse($second['rotated']);
    }

    public function testDetectsRotationViaFingerprint(): void
    {
        $path = $this->tmpDir . '/rotate.log';
        file_put_contents($path, str_repeat("line-marker\n", 200));

        $reader = new LogFileReader(tailWindowBytes: 512);
        $first = $reader->tail($path, 3);
        $cursor = $first['next_cursor'];
        $this->assertNotNull($cursor, 'Expected pagination cursor within a truncated read window');

        file_put_contents($path, "fresh\n");

        $second = $reader->tail($path, 3, $cursor, $first['fingerprint']);
        $this->assertTrue($second['rotated']);
        $this->assertSame(['fresh'], $second['lines']);
    }

    public function testFilteredTailFindsMatches(): void
    {
        $path = $this->tmpDir . '/security.log';
        $events = [
            '{"ts":"2026-07-12T10:00:00+00:00","event":"login_success","ip":"1.1.1.1","username":"alice"}',
            '{"ts":"2026-07-12T10:01:00+00:00","event":"login_fail","ip":"2.2.2.2","username":"bob"}',
            '{"ts":"2026-07-12T10:02:00+00:00","event":"login_fail","ip":"3.3.3.3","username":"carol"}',
        ];
        file_put_contents($path, implode("\n", $events) . "\n");

        $reader = new LogFileReader(searchScanBytes: 4096);
        $matcher = static fn (string $line): bool => str_contains($line, '"login_fail"');

        $result = $reader->tail($path, 10, null, null, $matcher);
        $this->assertCount(2, $result['lines']);
        $this->assertTrue($result['matches_exhausted']);
    }

    public function testEmptyFileReturnsNoLines(): void
    {
        $path = $this->tmpDir . '/empty.log';
        touch($path);

        $reader = new LogFileReader();
        $result = $reader->tail($path, 10);

        $this->assertSame([], $result['lines']);
        $this->assertNull($result['next_cursor']);
    }
}