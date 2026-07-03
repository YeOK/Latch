<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Support\SqliteIntegrity;
use PHPUnit\Framework\TestCase;

final class SqliteIntegrityTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-integrity-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec(
            'CREATE TABLE boards (id INTEGER PRIMARY KEY, name TEXT);
             INSERT INTO boards (id, name) VALUES (1, "General");'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testHealthyDatabasePasses(): void
    {
        $report = SqliteIntegrity::run($this->dbPath);

        $this->assertTrue($report['ok']);
        $this->assertSame('integrity_check', $report['checks'][0]['name']);
        $this->assertSame('foreign_key_check', $report['checks'][1]['name']);
    }

    public function testQuickCheckOnly(): void
    {
        $report = SqliteIntegrity::run($this->dbPath, quickOnly: true);

        $this->assertTrue($report['ok']);
        $this->assertCount(2, $report['checks']);
        $this->assertSame('quick_check', $report['checks'][0]['name']);
    }

    public function testTruncatedFileFails(): void
    {
        file_put_contents($this->dbPath, substr((string) file_get_contents($this->dbPath), 0, 64));

        $report = SqliteIntegrity::run($this->dbPath);

        $this->assertFalse($report['ok']);
    }

    public function testFormatHumanIncludesStatus(): void
    {
        $report = SqliteIntegrity::run($this->dbPath);
        $text = SqliteIntegrity::formatHuman($report);

        $this->assertStringContainsString('db-check: OK', $text);
    }
}