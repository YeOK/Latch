<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\TextDiff;
use Latch\Models\PostRevisionRepository;
use PHPUnit\Framework\TestCase;

final class PostRevisionRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private PostRevisionRepository $revisions;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-rev-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT);
             CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT);
             CREATE TABLE post_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                editor_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL
             );'
        );
        $pdo->exec(
            "INSERT INTO users (id, username) VALUES (1, 'alice'), (2, 'bob');
             INSERT INTO posts (id, topic_id, user_id, body) VALUES (10, 1, 1, 'Hello');"
        );

        $this->revisions = new PostRevisionRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testSaveAndListRevisions(): void
    {
        $this->revisions->save(10, 1, 'Hello');
        $this->revisions->save(10, 2, 'Hello world');

        $rows = $this->revisions->listForPost(10);

        $this->assertCount(2, $rows);
        $this->assertSame('bob', $rows[0]['editor_username']);
        $this->assertSame('Hello world', $rows[0]['body']);
        $this->assertSame('alice', $rows[1]['editor_username']);
        $this->assertSame(2, $this->revisions->countForPost(10));
    }

    public function testLatestEditorsForPosts(): void
    {
        $this->revisions->save(10, 1, 'Hello');
        $this->revisions->save(10, 2, 'Hello world');

        $latest = $this->revisions->latestEditorsForPosts([10, 99]);

        $this->assertArrayHasKey(10, $latest);
        $this->assertSame('bob', $latest[10]['editor_username']);
        $this->assertSame(2, $latest[10]['editor_id']);
        $this->assertArrayNotHasKey(99, $latest);
    }

    public function testTextDiffMarksChanges(): void
    {
        $diff = new TextDiff();
        $lines = $diff->lines("one\ntwo", "one\nthree");

        $kinds = array_column($lines, 'kind');
        $this->assertContains('remove', $kinds);
        $this->assertContains('add', $kinds);
        $this->assertContains('same', $kinds);
    }
}