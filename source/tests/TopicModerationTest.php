<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\BoardRepository;
use Latch\Models\PostRepository;
use Latch\Models\TopicRepository;
use PHPUnit\Framework\TestCase;

final class TopicModerationTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private TopicRepository $topics;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-mod-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                created_at TEXT NOT NULL
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                requires_login_to_read INTEGER NOT NULL DEFAULT 0,
                staff_only_topics INTEGER NOT NULL DEFAULT 0,
                icon_key TEXT NOT NULL DEFAULT "",
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                is_locked INTEGER NOT NULL DEFAULT 0,
                is_pinned INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                last_post_at TEXT NOT NULL,
                deleted_at TEXT,
                UNIQUE (board_id, slug)
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved",
                trashed_at TEXT,
                trashed_by_user_id INTEGER,
                trash_restore_topic_id INTEGER,
                trash_restore_board_id INTEGER
             );
             CREATE TABLE topic_watches (
                user_id INTEGER NOT NULL,
                topic_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, topic_id)
             );
             CREATE TABLE topic_read_state (
                user_id INTEGER NOT NULL,
                topic_id INTEGER NOT NULL,
                last_read_post_id INTEGER,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (user_id, topic_id)
             );
             INSERT INTO users (id, username, email, password_hash, created_at) VALUES
                (1, "alice", "alice@test", "hash", "2026-06-30T10:00:00+00:00"),
                (2, "bob", "bob@test", "hash", "2026-06-30T10:00:00+00:00");
             INSERT INTO boards (id, slug, name) VALUES
                (1, "general", "General"),
                (2, "news", "News");'
        );

        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db, $this->posts);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testMoveTopicChangesBoard(): void
    {
        $topic = $this->topics->create(1, 1, 'Move me', 'First post');
        $result = $this->topics->moveToBoard((int) $topic['id'], 2);
        $moved = $this->topics->findById((int) $topic['id']);

        $this->assertSame(2, $result['new_board_id']);
        $this->assertSame(2, (int) ($moved['board_id'] ?? 0));
    }

    public function testMergeMovesPostsAndSoftDeletesSource(): void
    {
        $source = $this->topics->create(1, 1, 'Source', 'Source OP');
        $target = $this->topics->create(1, 2, 'Target', 'Target OP');
        $this->posts->create((int) $source['id'], 1, 'Source reply');
        $this->posts->create((int) $target['id'], 2, 'Target reply');

        $result = $this->topics->mergeInto((int) $source['id'], (int) $target['id']);
        $sourceAfter = $this->topics->findById((int) $source['id']);
        $targetPosts = $this->posts->listActiveByTopicOrdered((int) $target['id']);

        $this->assertNotEmpty($sourceAfter['deleted_at'] ?? null);
        $this->assertSame(4, count($targetPosts));
        $this->assertSame(2, $result['posts_moved']);
    }

    public function testSplitCreatesNewTopicFromPost(): void
    {
        $topic = $this->topics->create(1, 1, 'Original', 'OP');
        $reply = $this->posts->create((int) $topic['id'], 2, 'Split here');
        $this->posts->create((int) $topic['id'], 1, 'After split');

        $result = $this->topics->splitFromPost((int) $topic['id'], (int) $reply['id'], 'Split topic');
        $originalPosts = $this->posts->listActiveByTopicOrdered((int) $topic['id']);
        $newPosts = $this->posts->listActiveByTopicOrdered($result['new_topic_id']);
        $newTopic = $this->topics->findById($result['new_topic_id']);

        $this->assertSame(1, count($originalPosts));
        $this->assertSame(2, count($newPosts));
        $this->assertSame('Split topic', $newTopic['title'] ?? '');
        $this->assertSame(2, (int) ($newTopic['user_id'] ?? 0));
    }
}