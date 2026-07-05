<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\BoardAcl;
use Latch\Core\Database;
use Latch\Core\ReputationService;
use Latch\Models\PostRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class ReputationServiceTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private ReputationService $reputation;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-rep-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $approved = PostRepository::APPROVAL_APPROVED;

        $pdo->exec(
            "CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
             CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                role TEXT DEFAULT 'member',
                created_at TEXT,
                banned_at TEXT,
                banned_until TEXT,
                reputation_score REAL,
                reputation_rank INTEGER,
                reputation_computed_at TEXT,
                rank_override INTEGER
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT,
                acl_read TEXT DEFAULT 'guest',
                min_rank_read INTEGER
             );
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, deleted_at TEXT);
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                user_id INTEGER,
                deleted_at TEXT,
                quarantined_at TEXT,
                approval_status TEXT DEFAULT '{$approved}',
                like_count INTEGER DEFAULT 0,
                dislike_count INTEGER DEFAULT 0
             );
             CREATE TABLE topic_reads (user_id INTEGER, topic_id INTEGER, last_read_at TEXT);
             CREATE TABLE topic_watches (user_id INTEGER, topic_id INTEGER, created_at TEXT);
             CREATE TABLE user_sessions (user_id INTEGER, last_seen_at TEXT);
             CREATE TABLE user_warnings (user_id INTEGER, created_at TEXT);
             CREATE TABLE reputation_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                score REAL,
                rank INTEGER,
                components_json TEXT,
                computed_at TEXT
             );"
        );

        $pdo->exec(
            "INSERT INTO users (id, username, email, role, created_at) VALUES
                (1, 'lurker', 'lurker@test', 'member', '2026-01-01T00:00:00+00:00'),
                (2, 'poster', 'poster@test', 'member', '2026-01-01T00:00:00+00:00'),
                (3, 'moduser', 'mod@test', 'mod', '2026-01-01T00:00:00+00:00');
             INSERT INTO boards (id, slug, min_rank_read) VALUES (1, 'general', 3);
             INSERT INTO topics (id, board_id, user_id) VALUES (1, 1, 2), (2, 1, 2);
             INSERT INTO posts (id, topic_id, user_id, like_count) VALUES
                (1, 1, 2, 5),
                (2, 1, 2, 3),
                (3, 2, 1, 0);
             INSERT INTO topic_reads (user_id, topic_id, last_read_at) VALUES
                (1, 1, '2026-06-20T00:00:00+00:00'),
                (1, 2, '2026-06-21T00:00:00+00:00');"
        );

        $this->users = new UserRepository($this->db);
        $this->reputation = new ReputationService(
            $this->db,
            $this->users,
            new SettingRepository($this->db),
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testStaffExcludedFromReputation(): void
    {
        $result = $this->reputation->computeForUser(3);
        $this->assertNull($result['rank']);

        $user = $this->users->findById(3);
        $this->assertNotNull($user);
        $this->assertNull($user['reputation_rank']);
    }

    public function testMemberReceivesRankAndPersists(): void
    {
        $result = $this->reputation->computeForUser(2);
        $this->assertGreaterThanOrEqual(1, $result['rank']);
        $this->assertGreaterThan(0.0, $result['score']);

        $user = $this->users->findById(2);
        $this->assertSame($result['rank'], (int) $user['reputation_rank']);
    }

    public function testLurkerCanEarnRankFromEngagement(): void
    {
        $result = $this->reputation->computeForUser(1);
        $this->assertGreaterThanOrEqual(1, $result['rank']);
        $this->assertGreaterThan(0, $result['components']['topics_read_90d']);
    }

    public function testBoardMinRankBlocksLowRankMember(): void
    {
        $board = ['acl_read' => 'guest', 'min_rank_read' => 3];
        $this->assertFalse(BoardAcl::allows($board, BoardAcl::ACTION_READ, true, 'member', false, 2));
        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_READ, true, 'member', false, 3));
        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_READ, true, 'mod', false, null));
    }

    public function testSaveAdminSettingsPersistsWeights(): void
    {
        $this->reputation->saveAdminSettings(
            ['topic' => 5.0, 'reply' => 4.0, 'dislike' => 2.0, 'read' => 3.0, 'watch' => 2.5, 'active_day' => 2.0, 'like_cap' => 8, 'warning_penalty' => 20.0],
            [2 => ['min_score' => 20.0, 'min_age_days' => 10]],
        );

        $weights = $this->reputation->configuredWeights();
        $this->assertEqualsWithDelta(5.0, $weights['topic'], 0.001);
        $this->assertSame(8, $weights['like_cap']);

        $thresholds = $this->reputation->configuredThresholds();
        $this->assertSame(20.0, $thresholds[2]['min_score']);
        $this->assertSame(10, $thresholds[2]['min_age_days']);
    }

    public function testRankOverrideLocksDisplayRank(): void
    {
        $this->users->setRankOverride(2, 5);
        $result = $this->reputation->computeForUser(2);
        $this->assertSame(5, $result['rank']);
    }
}