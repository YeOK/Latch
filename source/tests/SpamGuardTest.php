<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\Request;
use Latch\Core\SecurityLog;
use Latch\Core\SpamGuard;
use Latch\Models\PostRepository;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private SpamGuard $guard;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-spam-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, created_at TEXT);
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT, deleted_at TEXT);
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT,
                created_at TEXT, deleted_at TEXT, approval_status TEXT NOT NULL DEFAULT \'approved\'
             );'
        );
        $pdo->exec("INSERT INTO users (id, username, role, created_at) VALUES (1, 'newbie', 'member', '2026-01-01T00:00:00+00:00')");
        $pdo->exec("INSERT INTO users (id, username, role, created_at) VALUES (2, 'moduser', 'mod', '2026-01-01T00:00:00+00:00')");

        $settings = new SettingRepository($this->db);
        $settings->set('spam_link_limit_new_users', '1');
        $settings->set('spam_new_user_max_posts', '5');
        $settings->setBool('spam_approval_queue_enabled', true);
        $settings->setBool('spam_honeypot_enabled', true);

        $posts = new PostRepository($this->db);
        $logPath = sys_get_temp_dir() . '/latch-spam-test-' . getmypid() . '.log';
        @unlink($logPath);
        $securityLog = new SecurityLog($logPath);

        $this->guard = new SpamGuard(
            $settings,
            $posts,
            $securityLog,
            new Request(new Config(dirname(__DIR__) . '/config')),
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testLinkLimitForNewUser(): void
    {
        $user = ['id' => 1, 'role' => 'member'];
        $body = "Check [url=https://example.com]one[/url] and https://other.test/path";

        $error = $this->guard->linkLimitError($body, $user);

        $this->assertNotNull($error);
        $this->assertStringContainsString('link', strtolower($error));
    }

    public function testApprovalQueueForNewMember(): void
    {
        $user = ['id' => 1, 'role' => 'member'];

        $this->assertSame(PostRepository::APPROVAL_PENDING, $this->guard->approvalStatusForUser($user));
    }

    public function testStaffBypassApprovalQueue(): void
    {
        $user = ['id' => 2, 'role' => 'mod'];

        $this->assertSame(PostRepository::APPROVAL_APPROVED, $this->guard->approvalStatusForUser($user));
    }
}