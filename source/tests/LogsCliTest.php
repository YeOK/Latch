<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use PHPUnit\Framework\TestCase;

final class LogsCliTest extends TestCase
{
    private string $storageRoot;
    private string $latchBin;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/latch-logs-cli-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot . '/logs', 0750, true);
        $this->latchBin = dirname(__DIR__) . '/bin/latch';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $this->removeTree($this->storageRoot);
        }
    }

    public function testLogsListIncludesBuiltInSources(): void
    {
        $result = $this->runLatch(['logs', 'list', '--json']);

        $this->assertSame(0, $result['code']);
        $payload = json_decode($result['stdout'], true);
        $this->assertIsArray($payload);
        $ids = array_column($payload['sources'] ?? [], 'id');
        $this->assertContains('latch.security', $ids);
        $this->assertContains('latch.restore', $ids);
    }

    public function testLogsTailRequiresSource(): void
    {
        $result = $this->runLatch(['logs', 'tail']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Log source is required', $result['stderr']);
    }

    public function testLogsTailRejectsUnknownSource(): void
    {
        $result = $this->runLatch(['logs', 'tail', '--source=unknown.source']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Unknown log source', $result['stderr']);
    }

    public function testLogsTailMissingFileExitsZero(): void
    {
        $result = $this->runLatch(['logs', 'tail', '--source=latch.security', '--lines=5']);

        $this->assertSame(0, $result['code']);
        $this->assertSame('', trim($result['stdout']));
    }

    public function testLogsTailPrintsRedactedLines(): void
    {
        file_put_contents(
            $this->storageRoot . '/logs/security.log',
            "{\"ts\":\"2026-07-12T10:00:00+00:00\",\"event\":\"login_fail\",\"ip\":\"1.1.1.1\",\"username\":\"admin\",\"password\":\"secret\"}\n",
        );

        $result = $this->runLatch(['logs', 'tail', '--source=latch.security', '--lines=5']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('"password":"[REDACTED]"', $result['stdout']);
        $this->assertStringContainsString('login_fail', $result['stdout']);
    }

    public function testLogsTailAppliesEventFilter(): void
    {
        file_put_contents(
            $this->storageRoot . '/logs/security.log',
            "{\"ts\":\"2026-07-12T10:00:00+00:00\",\"event\":\"login_success\",\"ip\":\"1.1.1.1\"}\n"
            . "{\"ts\":\"2026-07-12T10:01:00+00:00\",\"event\":\"login_fail\",\"ip\":\"2.2.2.2\"}\n",
        );

        $result = $this->runLatch([
            'logs', 'tail',
            '--source=latch.security',
            '--event=login_fail',
            '--lines=10',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('login_fail', $result['stdout']);
        $this->assertStringNotContainsString('login_success', $result['stdout']);
    }

    public function testLogsTailJsonOutput(): void
    {
        file_put_contents(
            $this->storageRoot . '/logs/security.log',
            "{\"ts\":\"2026-07-12T10:00:00+00:00\",\"event\":\"login_fail\",\"ip\":\"1.1.1.1\"}\n",
        );

        $result = $this->runLatch([
            'logs', 'tail',
            '--source=latch.security',
            '--json',
        ]);

        $this->assertSame(0, $result['code']);
        $payload = json_decode($result['stdout'], true);
        $this->assertIsArray($payload);
        $this->assertSame('latch.security', $payload['source']);
        $this->assertCount(1, $payload['lines']);
        $this->assertSame('login_fail', $payload['parsed'][0]['event'] ?? null);
    }

    /**
     * @param list<string> $args
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLatch(array $args): array
    {
        $command = array_merge([PHP_BINARY, $this->latchBin], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, ['LATCH_STORAGE_PATH' => $this->storageRoot]);
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}