<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-profile-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE boards (
                id INTEGER PRIMARY KEY, slug TEXT, name TEXT,
                requires_login_to_read INTEGER DEFAULT 0,
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, bio TEXT, avatar_url TEXT, role TEXT DEFAULT "member", created_at TEXT);
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT, slug TEXT, deleted_at TEXT, last_post_at TEXT);
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT,
                created_at TEXT, deleted_at TEXT, quarantined_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved"
             );'
        );
        $pdo->exec(
            "INSERT INTO boards (id, slug, name, requires_login_to_read, acl_read) VALUES
                (1, 'news', 'News', 0, 'guest'),
                (2, 'staff', 'Staff', 1, 'member');
             INSERT INTO users (id, username, email, bio, created_at, role) VALUES
                (1, 'founder', 'founder@test', 'Founder bio', '2026-01-01T00:00:00+00:00', 'admin'),
                (2, 'deleted_2', 'deleted_2@deleted.local', '', '2026-01-02T00:00:00+00:00', 'member');
             INSERT INTO topics (id, board_id, user_id, title, slug, last_post_at) VALUES
                (1, 1, 1, 'Public topic', 'public-topic', '2026-06-29T10:00:00+00:00'),
                (2, 2, 1, 'Staff topic', 'staff-topic', '2026-06-29T11:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at, quarantined_at) VALUES
                (1, 1, 1, 'Hello world', '2026-06-29T10:00:00+00:00', NULL),
                (2, 2, 1, 'Staff only post', '2026-06-29T11:00:00+00:00', NULL),
                (3, 1, 1, 'Quarantined reply', '2026-06-29T12:00:00+00:00', '2026-06-29T12:05:00+00:00');"
        );

        $this->users = new UserRepository($this->db);
        $this->posts = new PostRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testIsAnonymisedDetectsDeletedAccounts(): void
    {
        $active = $this->users->findById(1);
        $deleted = $this->users->findById(2);

        $this->assertNotNull($active);
        $this->assertNotNull($deleted);
        $this->assertFalse($this->users->isAnonymised($active));
        $this->assertTrue($this->users->isAnonymised($deleted));
    }

    public function testProfileStatsExcludeLoginRequiredBoardsForGuests(): void
    {
        $stats = $this->users->profileStats(1, false, false);

        $this->assertSame(1, $stats['post_count']);
        $this->assertSame(1, $stats['topic_count']);
    }

    public function testProfileStatsIncludeAllBoardsForLoggedInViewers(): void
    {
        $stats = $this->users->profileStats(1, true, false);

        $this->assertSame(2, $stats['post_count']);
        $this->assertSame(2, $stats['topic_count']);
    }

    public function testProfileStatsIncludeQuarantinedPostsForMods(): void
    {
        $stats = $this->users->profileStats(1, true, true);

        $this->assertSame(3, $stats['post_count']);
        $this->assertSame(2, $stats['topic_count']);
    }

    public function testRecentPublicPostsRespectBoardAndQuarantineRules(): void
    {
        $guestPosts = $this->posts->recentPublicByUser(1, 10, false, false);
        $memberPosts = $this->posts->recentPublicByUser(1, 10, true, false);
        $modPosts = $this->posts->recentPublicByUser(1, 10, true, true);

        $this->assertCount(1, $guestPosts);
        $this->assertSame('Hello world', $guestPosts[0]['body']);

        $this->assertCount(2, $memberPosts);
        $this->assertSame('Staff only post', $memberPosts[0]['body']);
        $this->assertSame('Hello world', $memberPosts[1]['body']);

        $this->assertCount(3, $modPosts);
        $this->assertSame('Quarantined reply', $modPosts[0]['body']);
        $this->assertSame('Staff only post', $modPosts[1]['body']);
    }
}