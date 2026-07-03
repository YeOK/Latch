<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\AvatarUrl;
use PHPUnit\Framework\TestCase;

final class AvatarUrlTest extends TestCase
{
    public function testGravatarUrlIsDeterministic(): void
    {
        $avatars = new AvatarUrl();
        $expected = 'https://www.gravatar.com/avatar/' . md5('user@example.com') . '?s=48&d=identicon';

        $this->assertSame($expected, $avatars->gravatarUrl('User@Example.com', 48));
    }

    public function testGravatarUrlClampsSize(): void
    {
        $avatars = new AvatarUrl();
        $url = $avatars->gravatarUrl('a@b.co', 9999);

        $this->assertStringContainsString('?s=512&d=identicon', $url);
    }
}