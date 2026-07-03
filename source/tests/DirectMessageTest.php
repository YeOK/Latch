<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\MessageService;
use Latch\Core\NotificationService;
use Latch\Core\SpamGuard;
use Latch\Models\DirectMessageRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserBlockRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class DirectMessageTest extends TestCase
{
    private Database $db;
    private DirectMessageRepository $messages;
    private MessageService $service;
    private NotificationRepository $notifications;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                banned_at TEXT,
                banned_until TEXT,
                notify_email INTEGER NOT NULL DEFAULT 1,
                accept_messages INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
             );
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
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
             );
             CREATE TABLE dm_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_low INTEGER NOT NULL,
                user_high INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (user_low, user_high),
                CHECK (user_low < user_high)
             );
             CREATE TABLE dm_participants (
                conversation_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                last_read_message_id INTEGER,
                last_read_at TEXT,
                joined_at TEXT NOT NULL,
                PRIMARY KEY (conversation_id, user_id)
             );
             CREATE TABLE dm_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT "user",
                created_at TEXT NOT NULL,
                deleted_at TEXT
             );
             CREATE TABLE user_blocks (
                blocker_id INTEGER NOT NULL,
                blocked_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (blocker_id, blocked_id)
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                created_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved"
             );
             CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, ip_address TEXT, username TEXT, attempted_at TEXT, success INTEGER);',
        );

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO users (id, username, email, password_hash, role, accept_messages, created_at)
             VALUES (:id, :username, :email, :hash, :role, :accept_messages, :created_at)',
        );
        foreach (
            [
                [1, 'alice', 'member'],
                [2, 'bob', 'member'],
                [3, 'moduser', 'mod'],
            ] as [$id, $username, $role]
        ) {
            $stmt->execute([
                'id' => $id,
                'username' => $username,
                'email' => $username . '@example.test',
                'hash' => password_hash('secret', PASSWORD_DEFAULT),
                'role' => $role,
                'accept_messages' => 1,
                'created_at' => $now,
            ]);
        }

        $settings = new SettingRepository($this->db);
        $this->users = new UserRepository($this->db, new \Latch\Core\InputValidator(new \Latch\Core\Config(LATCH_ROOT . '/config')));
        $this->messages = new DirectMessageRepository($this->db);
        $this->notifications = new NotificationRepository($this->db);
        $notificationService = new NotificationService($this->notifications, $this->users);
        $spamGuard = new SpamGuard($settings, new \Latch\Models\PostRepository($this->db, new \Latch\Core\InputValidator(new \Latch\Core\Config(LATCH_ROOT . '/config'))), new \Latch\Core\SecurityLog(sys_get_temp_dir() . '/dm-test-security.log'), new \Latch\Core\Request(new \Latch\Core\Config(LATCH_ROOT . '/config')));
        $this->service = new MessageService(
            $this->messages,
            new UserBlockRepository($this->db),
            $this->users,
            $notificationService,
            $spamGuard,
        );
    }

    public function testMemberCanMessageWhenOptIn(): void
    {
        $alice = $this->users->findById(1);
        $this->assertNotNull($alice);

        $result = $this->service->sendToUser($alice, 2, 'Hello Bob');
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $this->notifications->countUnread(2));
    }

    public function testOptOutBlocksMemberUnlessStaffThreadExists(): void
    {
        $this->users->updateAcceptMessages(2, false);
        $alice = $this->users->findById(1);
        $this->assertNotNull($alice);

        $blocked = $this->service->sendToUser($alice, 2, 'Hello Bob');
        $this->assertFalse($blocked['ok']);

        $mod = $this->users->findById(3);
        $this->assertNotNull($mod);
        $warn = $this->service->deliverStaffWarning(2, $mod, 'Spam', 99);
        $this->assertTrue($warn['ok']);
        $this->assertSame(DirectMessageRepository::KIND_STAFF_WARNING, $this->messages->listMessages((int) $warn['conversation_id'], 2, 10)[0]['kind']);

        $reply = $this->service->sendToUser($this->users->findById(2), 3, 'Understood');
        $this->assertTrue($reply['ok']);
    }

    public function testStaffCanMessageOptedOutMember(): void
    {
        $this->users->updateAcceptMessages(2, false);
        $mod = $this->users->findById(3);
        $this->assertNotNull($mod);

        $result = $this->service->sendToUser($mod, 2, 'Please review the rules.');
        $this->assertTrue($result['ok']);
    }

    public function testBlocksPreventMemberSend(): void
    {
        (new UserBlockRepository($this->db))->block(2, 1);
        $alice = $this->users->findById(1);
        $this->assertNotNull($alice);

        $result = $this->service->sendToUser($alice, 2, 'Hello');
        $this->assertFalse($result['ok']);
    }
}