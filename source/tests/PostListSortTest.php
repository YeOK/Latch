<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Support\PostListSort;
use PHPUnit\Framework\TestCase;

final class PostListSortTest extends TestCase
{
    public function testNormalizeDefaultsUnknownToOldest(): void
    {
        $this->assertSame(PostListSort::OLDEST, PostListSort::normalize(null));
        $this->assertSame(PostListSort::OLDEST, PostListSort::normalize('nope'));
        $this->assertSame(PostListSort::NEWEST, PostListSort::normalize('newest'));
    }

    public function testSortPostsNewestReversesChronologicalOrder(): void
    {
        $posts = [
            ['id' => 1, 'created_at' => '2026-01-01T10:00:00+00:00', 'like_count' => 0],
            ['id' => 2, 'created_at' => '2026-01-02T10:00:00+00:00', 'like_count' => 0],
            ['id' => 3, 'created_at' => '2026-01-03T10:00:00+00:00', 'like_count' => 0],
        ];

        $sorted = PostListSort::sortPosts($posts, PostListSort::NEWEST);

        $this->assertSame([3, 2, 1], array_map(static fn (array $post): int => (int) $post['id'], $sorted));
    }

    public function testSortPostsTopUsesLikeCount(): void
    {
        $posts = [
            ['id' => 1, 'created_at' => '2026-01-01T10:00:00+00:00', 'like_count' => 1],
            ['id' => 2, 'created_at' => '2026-01-02T10:00:00+00:00', 'like_count' => 5],
            ['id' => 3, 'created_at' => '2026-01-03T10:00:00+00:00', 'like_count' => 2],
        ];

        $sorted = PostListSort::sortPosts($posts, PostListSort::TOP);

        $this->assertSame([2, 3, 1], array_map(static fn (array $post): int => (int) $post['id'], $sorted));
    }
}