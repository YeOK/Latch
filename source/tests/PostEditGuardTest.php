<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Auth;
use Latch\Core\PostEditGuard;
use PHPUnit\Framework\TestCase;

final class PostEditGuardTest extends TestCase
{
    public function testModCannotEditAdminPost(): void
    {
        $this->assertFalse(PostEditGuard::modMayEditAuthor(5, Auth::ROLE_ADMIN));
    }

    public function testModCannotEditFounderPost(): void
    {
        $this->assertFalse(PostEditGuard::modMayEditAuthor(Auth::FOUNDER_USER_ID, Auth::ROLE_ADMIN));
    }

    public function testModCanEditMemberPost(): void
    {
        $this->assertTrue(PostEditGuard::modMayEditAuthor(5, Auth::ROLE_MEMBER));
    }

    public function testModCanEditOtherModPost(): void
    {
        $this->assertTrue(PostEditGuard::modMayEditAuthor(5, Auth::ROLE_MOD));
    }
}