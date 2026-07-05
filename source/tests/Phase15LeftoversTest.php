<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\EmailChangeRepository;
use Latch\Models\PostRepository;
use Latch\Models\TopicRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class Phase15LeftoversTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private PostRepository $posts;
    private TopicRepository $topics;
    private EmailChangeRepository $emailChanges;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-p15-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT,
                created_at TEXT,
                email_verified_at TEXT,
                banned_at TEXT
             );
             CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER,
                user_id INTEGER,
                title TEXT,
                slug TEXT,
                created_at TEXT,
                deleted_at TEXT
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                user_id INTEGER,
                body TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT,
                approval_status TEXT DEFAULT \'approved\'
             );
             CREATE TABLE email_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                new_email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
             );'
        );
        $pdo->exec(
            "INSERT INTO users (id, username, email, password_hash, role, created_at, email_verified_at)
             VALUES (1, 'alice', 'alice@test', 'x', 'member', '2026-01-01', '2026-01-01'),
                    (2, 'bob', 'bob@test', 'x', 'member', '2026-01-01', '2026-01-01');
             INSERT INTO boards (id, slug, name) VALUES (1, 'general', 'General');
             INSERT INTO topics (id, board_id, user_id, title, slug, created_at)
             VALUES (10, 1, 1, 'Hello', 'hello', '2026-01-01');
             INSERT INTO posts (id, topic_id, user_id, body, created_at, updated_at)
             VALUES (100, 10, 1, 'Secret content', '2026-01-01', '2026-01-01');"
        );

        $this->users = new UserRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db, $this->posts);
        $this->emailChanges = new EmailChangeRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testEmailChangeTokenFlow(): void
    {
        $token = 'test-token-abc';
        $this->emailChanges->create(1, 'new@test', $token);

        $row = $this->emailChanges->findValid($token);
        $this->assertNotNull($row);
        $this->assertSame('new@test', $row['new_email']);

        $this->users->updateEmail(1, 'new@test');
        $this->emailChanges->markUsed((int) $row['id']);
        $this->assertNull($this->emailChanges->findValid($token));

        $user = $this->users->findById(1);
        $this->assertSame('new@test', $user['email']);
    }

    public function testAnonymisePostAndTopicContent(): void
    {
        $this->posts->anonymiseContentByUser(1);
        $this->topics->anonymiseTitlesByUser(1);

        $post = $this->posts->findById(100);
        $topic = $this->topics->findById(10);

        $this->assertSame('[deleted]', $post['body']);
        $this->assertSame('[deleted]', $topic['title']);
    }
}