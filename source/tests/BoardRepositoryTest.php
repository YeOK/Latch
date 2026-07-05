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
use Latch\Models\BoardRepository;
use PHPUnit\Framework\TestCase;

final class BoardRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private BoardRepository $boards;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-board-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE boards (
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
             INSERT INTO boards (id, slug, name, acl_topic) VALUES
                (1, "general", "General", "member"),
                (2, "news", "News", "mod");'
        );

        $this->boards = new BoardRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCanCreateTopicOnOpenBoard(): void
    {
        $board = $this->boards->findById(1) ?? [];

        $this->assertTrue($this->boards->canCreateTopic($board, true, 'member', false));
        $this->assertTrue($this->boards->canCreateTopic($board, true, 'mod', false));
    }

    public function testStaffOnlyBoardBlocksMembers(): void
    {
        $board = $this->boards->findById(2) ?? [];

        $this->assertFalse($this->boards->canCreateTopic($board, true, 'member', false));
        $this->assertTrue($this->boards->canCreateTopic($board, true, 'mod', false));
    }

    public function testCreatePersistsAcl(): void
    {
        $board = $this->boards->create(
            'Announcements',
            'Staff posts',
            BoardAcl::ROLE_MEMBER,
            BoardAcl::ROLE_MOD,
            BoardAcl::ROLE_MEMBER,
        );

        $this->assertSame(BoardAcl::ROLE_MOD, $board['acl_topic'] ?? '');
        $this->assertFalse($this->boards->canCreateTopic($board, true, 'member', false));
        $this->assertTrue($this->boards->canCreateTopic($board, true, 'admin', false));
    }

    public function testReadOnlyBoardForGuests(): void
    {
        $board = $this->boards->create('Public', '', BoardAcl::ROLE_GUEST, BoardAcl::ROLE_MEMBER, BoardAcl::ROLE_MEMBER);

        $this->assertTrue($this->boards->canRead($board, false, false, null));
        $this->assertFalse($this->boards->canReply($board, false, null, false));
    }

    public function testModOnlyReadBoard(): void
    {
        $board = ['acl_read' => BoardAcl::ROLE_MOD, 'acl_topic' => 'member', 'acl_reply' => 'member'];

        $this->assertFalse($this->boards->canRead($board, true, false, 'member'));
        $this->assertTrue($this->boards->canRead($board, true, false, 'mod'));
    }
}