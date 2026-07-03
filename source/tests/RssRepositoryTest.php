<?php

declare(strict_types=1);

namespace Latch\Tests;

use DOMDocument;
use Latch\Core\Database;
use Latch\Core\PostFormatter;
use Latch\Core\RssFeed;
use Latch\Models\RssRepository;
use PHPUnit\Framework\TestCase;

final class RssRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private RssRepository $rss;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-rss-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE boards (
                id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT DEFAULT "",
                requires_login_to_read INTEGER DEFAULT 0,
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, avatar_url TEXT);
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT, slug TEXT, deleted_at TEXT, last_post_at TEXT);
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT,
                created_at TEXT, deleted_at TEXT, quarantined_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved"
             );'
        );
        $pdo->exec(
            "INSERT INTO boards (id, slug, name) VALUES (1, 'news', 'News'), (2, 'staff', 'Staff');
             INSERT INTO users (id, username, email) VALUES (1, 'yeok', 'yeok@test');
             INSERT INTO topics (id, board_id, user_id, title, slug, last_post_at) VALUES
                (1, 1, 1, 'Site launch', 'site-launch', '2026-06-29T10:00:00+00:00'),
                (2, 2, 1, 'Staff only', 'staff-only', '2026-06-29T11:00:00+00:00');
             UPDATE boards SET acl_read = 'member', requires_login_to_read = 1 WHERE id = 2;
             INSERT INTO posts (id, topic_id, user_id, body, created_at) VALUES
                (1, 1, 1, 'Hello **world**', '2026-06-29T10:00:00+00:00'),
                (2, 2, 1, 'Hidden board post', '2026-06-29T11:00:00+00:00'),
                (3, 1, 1, 'Follow-up reply', '2026-06-29T12:00:00+00:00');"
        );

        $this->rss = new RssRepository($this->db, new PostFormatter());
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testSiteFeedExcludesLoginRequiredBoardsForGuests(): void
    {
        $topics = $this->rss->recentTopicsForSite(10, false, false);

        $this->assertCount(1, $topics);
        $this->assertSame('Site launch', $topics[0]['title']);
    }

    public function testBoardFeedReturnsBoardTopics(): void
    {
        $topics = $this->rss->recentTopicsForBoard(1, 10);

        $this->assertCount(1, $topics);
        $this->assertSame('news', $topics[0]['board_slug']);
    }

    public function testTopicFeedReturnsPostsInOrder(): void
    {
        $posts = $this->rss->postsForTopic(1, false);

        $this->assertCount(2, $posts);
        $this->assertSame('Hello **world**', $posts[0]['body']);
        $this->assertSame('Follow-up reply', $posts[1]['body']);
    }

    public function testEndToEndFeedXmlForSite(): void
    {
        $topics = $this->rss->recentTopicsForSite(10, false, false);
        $feed = new RssFeed('Test', 'https://example.test/', 'Desc', 'https://example.test/feed.xml');

        foreach ($topics as $topic) {
            $feed->addItem(
                (string) $topic['title'],
                'https://example.test/topic/' . $topic['id'],
                'https://example.test/topic/' . $topic['id'],
                (string) $topic['last_post_at'],
                $this->rss->plainExcerpt((string) $topic['first_post_body']),
                (string) $topic['author_name'],
            );
        }

        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($feed->render()));
        $this->assertSame(1, $doc->getElementsByTagName('item')->length);
    }
}