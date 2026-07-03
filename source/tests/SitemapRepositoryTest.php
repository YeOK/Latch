<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\SitemapRepository;
use PHPUnit\Framework\TestCase;

final class SitemapRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private SitemapRepository $sitemap;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-sitemap-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE boards (
                id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT DEFAULT "",
                sort_order INTEGER DEFAULT 0,
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT, slug TEXT, deleted_at TEXT, last_post_at TEXT);
             CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT, deleted_at TEXT, quarantined_at TEXT, approval_status TEXT DEFAULT "approved");'
        );
        $pdo->exec(
            "INSERT INTO boards (id, slug, name, sort_order) VALUES (1, 'news', 'News', 0), (2, 'staff', 'Staff', 1);
             INSERT INTO topics (id, board_id, user_id, title, slug, last_post_at) VALUES
                (1, 1, 1, 'Public topic', 'public-topic', '2026-06-29T10:00:00+00:00'),
                (2, 2, 1, 'Members topic', 'members-topic', '2026-06-29T11:00:00+00:00');
             UPDATE boards SET acl_read = 'member' WHERE id = 2;
             INSERT INTO posts (id, topic_id, user_id, body, created_at, approval_status) VALUES
                (1, 1, 1, 'Hello', '2026-06-29T10:00:00+00:00', 'approved'),
                (2, 2, 1, 'Hidden', '2026-06-29T11:00:00+00:00', 'approved');"
        );

        $this->sitemap = new SitemapRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testPublicBoardsExcludeMemberOnlyBoards(): void
    {
        $boards = $this->sitemap->publicBoards(false);

        $this->assertCount(1, $boards);
        $this->assertSame('news', $boards[0]['slug']);
    }

    public function testPublicTopicsExcludeMemberOnlyBoards(): void
    {
        $topics = $this->sitemap->publicTopics(false, 10);

        $this->assertCount(1, $topics);
        $this->assertSame(1, (int) $topics[0]['id']);
    }

    public function testMembersOnlySiteReturnsNoUrls(): void
    {
        $this->assertSame([], $this->sitemap->publicBoards(true));
        $this->assertSame([], $this->sitemap->publicTopics(true, 10));
    }
}