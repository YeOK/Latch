<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\NotificationService;
use Latch\Models\NotificationRepository;
use Latch\Models\PostReactionRepository;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class PostReactionRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private PostReactionRepository $reactions;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-vote-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT);
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT);
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                user_id INTEGER,
                body TEXT,
                deleted_at TEXT,
                quarantined_at TEXT,
                approval_status TEXT DEFAULT "approved",
                like_count INTEGER NOT NULL DEFAULT 0,
                dislike_count INTEGER NOT NULL DEFAULT 0
             );
             CREATE TABLE post_reactions (
                post_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                vote TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (post_id, user_id)
             );
             CREATE TABLE user_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                message TEXT NOT NULL,
                url TEXT NOT NULL,
                actor_id INTEGER,
                topic_id INTEGER,
                post_id INTEGER,
                meta_json TEXT,
                created_at TEXT NOT NULL,
                read_at TEXT
             );'
        );
        $pdo->exec(
            "INSERT INTO users (id, username, email) VALUES
                (1, 'alice', 'alice@test'),
                (2, 'bob', 'bob@test');
             INSERT INTO topics (id, board_id, user_id, title) VALUES (1, 1, 1, 'Thread');
             INSERT INTO posts (id, topic_id, user_id, body) VALUES (10, 1, 1, 'Hello');"
        );

        $this->reactions = new PostReactionRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testLikeIncrementsCount(): void
    {
        $result = $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_LIKE);

        $this->assertTrue($result['became_like']);
        $this->assertSame(1, $result['like_count']);
        $this->assertSame(0, $result['dislike_count']);
        $this->assertSame(PostReactionRepository::VOTE_LIKE, $result['viewer_vote']);
    }

    public function testToggleOffLike(): void
    {
        $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_LIKE);
        $result = $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_LIKE);

        $this->assertFalse($result['became_like']);
        $this->assertSame(0, $result['like_count']);
        $this->assertNull($result['viewer_vote']);
    }

    public function testSwitchFromLikeToDislike(): void
    {
        $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_LIKE);
        $result = $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_DISLIKE);

        $this->assertFalse($result['became_like']);
        $this->assertSame(0, $result['like_count']);
        $this->assertSame(1, $result['dislike_count']);
        $this->assertSame(PostReactionRepository::VOTE_DISLIKE, $result['viewer_vote']);
    }

    public function testCannotVoteOnOwnPost(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->reactions->setVote(10, 1, PostReactionRepository::VOTE_LIKE);
    }

    public function testLikeCreatesNotification(): void
    {
        $users = new UserRepository($this->db);
        $notifications = new NotificationRepository($this->db);
        $service = new NotificationService($notifications, $users);

        $this->reactions->setVote(10, 2, PostReactionRepository::VOTE_LIKE);

        $topic = ['id' => 1, 'user_id' => 1, 'title' => 'Thread'];
        $post = ['id' => 10, 'user_id' => 1];
        $actor = ['id' => 2, 'username' => 'bob'];
        $service->onPostLiked($post, $topic, $actor);

        $this->assertSame(1, $notifications->countUnread(1));
        $items = $notifications->listForUser(1);
        $this->assertSame(NotificationRepository::TYPE_POST_LIKE, $items[0]['event_type']);
    }
}