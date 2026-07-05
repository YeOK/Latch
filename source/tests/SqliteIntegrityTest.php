<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


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

    public function testForeignKeyCheckIgnoresAuthorOrphansAfterUserPurge(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY);
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL REFERENCES boards(id),
                user_id INTEGER NOT NULL REFERENCES users(id),
                title TEXT NOT NULL
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id),
                body TEXT NOT NULL
             );
             INSERT INTO users (id) VALUES (2);
             INSERT INTO topics (id, board_id, user_id, title) VALUES (10, 1, 2, "Thread");
             INSERT INTO posts (id, topic_id, user_id, body) VALUES (100, 10, 2, "Hello");
             DELETE FROM users WHERE id = 2;'
        );

        $report = SqliteIntegrity::run($this->dbPath);

        $this->assertTrue($report['ok']);
        $this->assertSame('foreign_key_check', $report['checks'][1]['name']);
        $this->assertStringContainsString('author orphan(s) ignored', (string) $report['checks'][1]['detail']);
    }

    public function testForeignKeyCheckStillFailsForUnexpectedViolations(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec(
            'CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL REFERENCES boards(id),
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL
             );
             INSERT INTO topics (id, board_id, user_id, title) VALUES (10, 999, 1, "Orphan board");'
        );

        $report = SqliteIntegrity::run($this->dbPath);

        $this->assertFalse($report['ok']);
        $this->assertIsArray($report['checks'][1]['detail']);
    }
}