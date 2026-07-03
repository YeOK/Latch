<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\NotificationService;
use Latch\Models\NotificationRepository;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class NotificationRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private NotificationRepository $notifications;
    private NotificationService $service;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-notify-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT);
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
                (2, 'bob', 'bob@test'),
                (3, 'carol', 'carol@test');"
        );

        $this->users = new UserRepository($this->db);
        $this->notifications = new NotificationRepository($this->db);
        $this->service = new NotificationService($this->notifications, $this->users);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCountUnreadAndMarkAllRead(): void
    {
        $this->notifications->create(1, NotificationRepository::TYPE_TOPIC_REPLY, 'Reply', '/topic/1');
        $this->notifications->create(1, NotificationRepository::TYPE_POST_QUOTE, 'Quote', '/topic/1#post-2');

        $this->assertSame(2, $this->notifications->countUnread(1));
        $this->assertSame(2, $this->notifications->markAllRead(1));
        $this->assertSame(0, $this->notifications->countUnread(1));
    }

    public function testMarkReadIsScopedToUser(): void
    {
        $id = $this->notifications->create(1, NotificationRepository::TYPE_TOPIC_REPLY, 'Reply', '/topic/1');
        $this->notifications->create(2, NotificationRepository::TYPE_TOPIC_REPLY, 'Other', '/topic/2');

        $this->assertFalse($this->notifications->markRead($id, 2));
        $this->assertTrue($this->notifications->markRead($id, 1));
        $this->assertSame(0, $this->notifications->countUnread(1));
        $this->assertSame(1, $this->notifications->countUnread(2));
    }

    public function testOnReplyNotifiesTopicAuthorNotSelf(): void
    {
        $topic = ['id' => 5, 'user_id' => 1, 'title' => 'Help thread'];
        $post = ['id' => 10, 'body' => 'Thanks!', 'approval_status' => PostRepository::APPROVAL_APPROVED];
        $actor = ['id' => 2, 'username' => 'bob'];

        $this->service->onReply($topic, $post, $actor);

        $this->assertSame(1, $this->notifications->countUnread(1));
        $this->assertSame(0, $this->notifications->countUnread(2));

        $items = $this->notifications->listForUser(1);
        $this->assertSame(NotificationRepository::TYPE_TOPIC_REPLY, $items[0]['event_type']);
        $this->assertStringContainsString('@bob', $items[0]['message']);
    }

    public function testOnReplyParsesQuotes(): void
    {
        $topic = ['id' => 5, 'user_id' => 1, 'title' => 'Discussion'];
        $post = [
            'id' => 11,
            'body' => '[quote="carol"]Earlier point[/quote] I agree.',
            'approval_status' => PostRepository::APPROVAL_APPROVED,
        ];
        $actor = ['id' => 2, 'username' => 'bob'];

        $this->service->onReply($topic, $post, $actor);

        $this->assertSame(1, $this->notifications->countUnread(1));
        $this->assertSame(1, $this->notifications->countUnread(3));

        $quote = $this->notifications->listForUser(3)[0];
        $this->assertSame(NotificationRepository::TYPE_POST_QUOTE, $quote['event_type']);
    }

    public function testQuotedUsernamesParser(): void
    {
        $body = '[quote="Alice"]Hi[/quote] and [quote author="bob"]Yo[/quote]';

        $names = $this->service->quotedUsernames($body);

        $this->assertSame(['Alice', 'bob'], $names);
    }

    public function testStaffActionSkipsSelf(): void
    {
        $topic = ['id' => 7, 'user_id' => 2, 'title' => 'My topic'];
        $actor = ['id' => 2, 'username' => 'bob'];

        $this->service->onStaffTopicAction('topic.lock', $topic, $actor, 'locked');

        $this->assertSame(0, $this->notifications->countUnread(2));
    }

    public function testOnPostPendingApprovalNotifiesAuthor(): void
    {
        $topic = ['id' => 9, 'user_id' => 2, 'title' => 'General chat'];
        $post = ['id' => 20, 'approval_status' => PostRepository::APPROVAL_PENDING];
        $author = ['id' => 2, 'username' => 'dave01'];

        $this->service->onPostPendingApproval($topic, $post, $author, false);

        $this->assertSame(1, $this->notifications->countUnread(2));
        $items = $this->notifications->listForUser(2);
        $this->assertSame(NotificationRepository::TYPE_POST_PENDING, $items[0]['event_type']);
        $this->assertStringContainsString('awaiting staff approval', $items[0]['message']);
        $this->assertSame('/topic/9#post-20', $items[0]['url']);
    }
}