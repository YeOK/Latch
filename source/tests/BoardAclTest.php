<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\BoardAcl;
use PHPUnit\Framework\TestCase;

final class BoardAclTest extends TestCase
{
    public function testGuestCanReadPublicBoard(): void
    {
        $board = ['acl_read' => BoardAcl::ROLE_GUEST];

        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_READ, false, null, false));
    }

    public function testGuestCannotReadMembersOnlyBoard(): void
    {
        $board = ['acl_read' => BoardAcl::ROLE_MEMBER];

        $this->assertFalse(BoardAcl::allows($board, BoardAcl::ACTION_READ, false, null, false));
    }

    public function testMemberCanReplyOnMemberBoard(): void
    {
        $board = ['acl_reply' => BoardAcl::ROLE_MEMBER];

        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_REPLY, true, 'member', false));
    }

    public function testMemberCannotStartTopicOnStaffBoard(): void
    {
        $board = ['acl_topic' => BoardAcl::ROLE_MOD];

        $this->assertFalse(BoardAcl::allows($board, BoardAcl::ACTION_TOPIC, true, 'member', false));
        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_TOPIC, true, 'mod', false));
    }

    public function testSqlFilterForGuestOnlyIncludesGuestBoards(): void
    {
        $this->assertSame(" AND b.acl_read IN ('guest')", BoardAcl::sqlBoardReadFilter(false, null));
    }

    public function testSqlFilterForModIncludesLowerRoles(): void
    {
        $sql = BoardAcl::sqlBoardReadFilter(true, 'mod');

        $this->assertStringContainsString("'guest'", $sql);
        $this->assertStringContainsString("'member'", $sql);
        $this->assertStringContainsString("'mod'", $sql);
        $this->assertStringNotContainsString("'admin'", $sql);
    }

    public function testMinRankGateBlocksMemberBelowThreshold(): void
    {
        $board = ['acl_read' => 'member', 'min_rank_read' => 4];

        $this->assertFalse(BoardAcl::allows($board, BoardAcl::ACTION_READ, true, 'member', false, 2));
        $this->assertTrue(BoardAcl::allows($board, BoardAcl::ACTION_READ, true, 'member', false, 4));
    }
}