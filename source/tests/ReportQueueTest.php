<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\ReportQuarantine;
use Latch\Core\ReportReasons;
use Latch\Core\SecurityLog;
use Latch\Models\PostRepository;
use Latch\Models\ReportRepository;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class ReportQueueTest extends TestCase
{
    private Database $db;
    private ReportRepository $reports;
    private PostRepository $posts;
    private ReportQuarantine $quarantine;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT,
                created_at TEXT
             );
             CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER,
                user_id INTEGER,
                title TEXT,
                slug TEXT,
                created_at TEXT,
                last_post_at TEXT,
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
                quarantined_at TEXT,
                quarantined_by_report_id INTEGER,
                approval_status TEXT NOT NULL DEFAULT "approved"
             );
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE reports (
                id INTEGER PRIMARY KEY,
                reporter_id INTEGER NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NOT NULL,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "open",
                created_at TEXT NOT NULL,
                severity TEXT NOT NULL DEFAULT "medium",
                reason_code TEXT NOT NULL DEFAULT "other",
                reason_detail TEXT NOT NULL DEFAULT "",
                quarantine_applied INTEGER NOT NULL DEFAULT 0,
                resolution_action TEXT,
                resolved_by INTEGER,
                resolved_at TEXT
             );
             INSERT INTO users (id, username, email, password_hash, role, created_at) VALUES
                (1, "author", "a@test", "x", "member", "2026-01-01T00:00:00+00:00"),
                (2, "reporter", "r@test", "x", "member", "2026-01-01T00:00:00+00:00"),
                (3, "mod", "m@test", "x", "mod", "2026-01-01T00:00:00+00:00");
             INSERT INTO boards (id, slug, name) VALUES (1, "general", "General");
             INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at)
             VALUES (10, 1, 1, "Thread", "thread", "2026-01-01T00:00:00+00:00", "2026-01-01T00:00:00+00:00");
             INSERT INTO posts (id, topic_id, user_id, body, created_at)
             VALUES (100, 10, 1, "Offensive post", "2026-01-01T00:00:00+00:00");'
        );

        $this->reports = new ReportRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $logPath = sys_get_temp_dir() . '/latch-report-queue-' . getmypid() . '.log';
        @unlink($logPath);
        $settings = new SettingRepository($this->db);
        $this->quarantine = new ReportQuarantine(
            $this->reports,
            $this->posts,
            new ReportReasons($settings),
            new SecurityLog($logPath),
        );
    }

    public function testHighSeverityReportTriggersQuarantine(): void
    {
        $reportId = $this->reports->create(2, 'post', 100, 'harassment', 'high', 'Repeated insults');
        $this->assertTrue($this->quarantine->shouldQuarantine('high', 100));

        $this->quarantine->apply(100, $reportId, '127.0.0.1', 2);

        $post = $this->posts->findById(100);
        $this->assertNotNull($post['quarantined_at']);
        $this->assertSame($reportId, (int) $post['quarantined_by_report_id']);
        $this->assertSame(1, $this->reports->openCount());
    }

    public function testResolveReportsLiftsQuarantine(): void
    {
        $reportId = $this->reports->create(2, 'post', 100, 'harassment', 'high', 'Repeated insults');
        $this->quarantine->apply(100, $reportId, '127.0.0.1', 2);

        $this->reports->resolveOpenForTarget('post', 100, 3, 'dismissed', 'clear');
        $this->quarantine->maybeLiftForPost(100, '127.0.0.1', 3);

        $post = $this->posts->findById(100);
        $this->assertNull($post['quarantined_at']);
        $this->assertSame(0, $this->reports->openCount());
    }
}