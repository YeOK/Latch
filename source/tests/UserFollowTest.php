<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\MentionParser;
use Latch\Core\NotificationService;
use Latch\Models\BoardRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\UserFollowRepository;
use Latch\Models\UserRepository;
use Latch\Support\UserDependencyCleanup;
use PHPUnit\Framework\TestCase;

final class UserFollowTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private UserFollowRepository $follows;
    private NotificationRepository $notifications;
    private NotificationService $notify;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-follow-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                role TEXT DEFAULT "member",
                deleted_at TEXT,
                banned_at TEXT,
                banned_until TEXT,
                locked_until TEXT,
                reputation_rank INTEGER
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT,
                name TEXT,
                acl_read TEXT DEFAULT "guest"
             );
             CREATE TABLE user_follows (
                follower_id INTEGER NOT NULL,
                following_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (follower_id, following_id)
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
            "INSERT INTO users (id, username, email, role) VALUES
                (1, 'alice', 'a@test', 'member'),
                (2, 'bob', 'b@test', 'member'),
                (3, 'carol', 'c@test', 'member');
             INSERT INTO boards (id, slug, name, acl_read) VALUES
                (1, 'general', 'General', 'guest'),
                (2, 'staff', 'Staff', 'mod');"
        );

        $this->users = new UserRepository($this->db);
        $this->follows = new UserFollowRepository($this->db, $this->users);
        $this->notifications = new NotificationRepository($this->db);
        $this->notify = new NotificationService(
            $this->notifications,
            $this->users,
            null,
            new MentionParser(),
            $this->follows,
            new BoardRepository($this->db),
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testFollowUnfollowAndNoSelfFollow(): void
    {
        $this->assertFalse($this->follows->isFollowing(1, 2));
        $ok = $this->follows->follow(1, 2);
        $this->assertTrue($ok['ok']);
        $this->assertTrue($this->follows->isFollowing(1, 2));
        $this->assertSame(1, $this->follows->followerCount(2));
        $this->assertSame(1, $this->follows->followingCount(1));

        $self = $this->follows->follow(1, 1);
        $this->assertFalse($self['ok']);

        $off = $this->follows->unfollow(1, 2);
        $this->assertFalse($off['following']);
        $this->assertFalse($this->follows->isFollowing(1, 2));
    }

    public function testCannotFollowDeletedOrBanned(): void
    {
        $this->db->pdo()->exec("UPDATE users SET deleted_at = '2026-08-01T00:00:00+00:00' WHERE id = 2");
        $this->assertFalse($this->follows->follow(1, 2)['ok']);

        $this->db->pdo()->exec('UPDATE users SET deleted_at = NULL, banned_at = "2026-08-01T00:00:00+00:00" WHERE id = 2');
        $this->assertFalse($this->follows->follow(1, 2)['ok']);
    }

    public function testNewTopicNotifiesFollowersWhoCanRead(): void
    {
        $this->follows->follow(2, 1);
        $this->follows->follow(3, 1);

        $alice = $this->users->findById(1);
        $public = $this->notify->onFollowedUserNewTopic(
            ['id' => 10, 'title' => 'Hello world', 'user_id' => 1],
            $alice ?? [],
            ['id' => 1, 'acl_read' => 'guest'],
            false,
        );
        $this->assertSame(2, $public);

        $staffOnly = $this->notify->onFollowedUserNewTopic(
            ['id' => 11, 'title' => 'Mods only', 'user_id' => 1],
            $alice ?? [],
            ['id' => 2, 'acl_read' => 'mod'],
            false,
        );
        $this->assertSame(0, $staffOnly);

        $rows = $this->db->pdo()->query(
            "SELECT user_id, event_type FROM user_notifications ORDER BY id"
        )->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame(NotificationRepository::TYPE_FOLLOWED_USER_TOPIC, $rows[0]['event_type']);
    }

    public function testUnfollowStopsNotifications(): void
    {
        $this->follows->follow(2, 1);
        $this->follows->unfollow(2, 1);
        $alice = $this->users->findById(1);
        $sent = $this->notify->onFollowedUserNewTopic(
            ['id' => 12, 'title' => 'After unfollow', 'user_id' => 1],
            $alice ?? [],
            ['id' => 1, 'acl_read' => 'guest'],
            false,
        );
        $this->assertSame(0, $sent);
    }

    public function testPurgeRemovesFollowRows(): void
    {
        $this->follows->follow(2, 1);
        $this->follows->follow(1, 3);
        (new UserDependencyCleanup())->deleteForUser($this->db->pdo(), 1);

        $this->assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM user_follows')->fetchColumn());
    }
}
