<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Migrator;
use Latch\Core\PostFormatter;
use Latch\Import\Phpbb\BbcodeConverter;
use Latch\Import\Phpbb\PhpbbImporter;
use Latch\Import\Phpbb\PhpbbReader;
use PHPUnit\Framework\TestCase;

final class PhpbbImportTest extends TestCase
{
    private string $dbPath;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-phpbb-import-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $migrator = new Migrator($this->db, LATCH_ROOT . '/database/migrations');
        $migrator->migrate();
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        $wal = $this->dbPath . '-wal';
        $shm = $this->dbPath . '-shm';
        if (is_file($wal)) {
            @unlink($wal);
        }
        if (is_file($shm)) {
            @unlink($shm);
        }
    }

    public function testDryRunCountsEntities(): void
    {
        $bundle = (new PhpbbReader())->loadBundleFile(
            LATCH_ROOT . '/scripts/fixtures/phpbb/minimal-bundle.json',
        );
        $report = (new PhpbbImporter($this->db, new BbcodeConverter()))->dryRun($bundle);

        $this->assertTrue($report->ok());
        $this->assertSame(2, $report->count('users'));
        $this->assertSame(1, $report->count('boards'));
        $this->assertSame(1, $report->count('topics'));
        $this->assertSame(2, $report->count('posts'));
    }

    public function testConfirmImportsMinimalBundle(): void
    {
        $bundle = (new PhpbbReader())->loadBundleFile(
            LATCH_ROOT . '/scripts/fixtures/phpbb/minimal-bundle.json',
        );
        $report = (new PhpbbImporter($this->db, new BbcodeConverter()))->confirm($bundle);

        $this->assertTrue($report->ok(), $report->toHuman());

        $pdo = $this->db->pdo();
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM boards WHERE slug = 'general'")->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        $this->assertSame(6, (int) $pdo->query("SELECT COUNT(*) FROM import_map WHERE source = 'phpbb'")->fetchColumn());

        $topic = $pdo->query('SELECT title, is_pinned FROM topics LIMIT 1')->fetch();
        $this->assertSame('Welcome to the migrated forum', $topic['title']);
        $this->assertSame(1, (int) $topic['is_pinned']);

        $board = $pdo->query("SELECT slug FROM boards WHERE slug = 'general'")->fetch();
        $this->assertNotFalse($board);

        $post = $pdo->query('SELECT body FROM posts ORDER BY id ASC LIMIT 1')->fetch();
        $html = (new PostFormatter())->format((string) $post['body']);
        $this->assertStringContainsString('<strong>Hello</strong>', $html);
        $this->assertStringContainsString('forum.example.com', $html);
    }

    public function testRejectsImportWhenTopicsExist(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO users (username, email, password_hash, role, created_at)
                    VALUES ('seed', 'seed@test', 'x', 'member', '2026-01-01')");
        $pdo->exec("INSERT INTO boards (slug, name, description, sort_order) VALUES ('b', 'B', '', 0)");
        $pdo->exec("INSERT INTO topics (board_id, user_id, title, slug, created_at, last_post_at)
                    VALUES (1, 1, 'Existing', 'existing', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO posts (topic_id, user_id, body, created_at, approval_status)
                    VALUES (1, 1, 'hi', '2026-01-01', 'approved')");

        $bundle = (new PhpbbReader())->loadBundleFile(
            LATCH_ROOT . '/scripts/fixtures/phpbb/minimal-bundle.json',
        );
        $report = (new PhpbbImporter($this->db, new BbcodeConverter()))->dryRun($bundle);

        $this->assertFalse($report->ok());
    }
}