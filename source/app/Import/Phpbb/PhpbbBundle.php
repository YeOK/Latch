<?php

declare(strict_types=1);

namespace Latch\Import\Phpbb;

/**
 * Portable phpBB export bundle (JSON).
 */
final class PhpbbBundle
{
    /**
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $forums
     * @param list<array<string, mixed>> $users
     * @param list<array<string, mixed>> $topics
     * @param list<array<string, mixed>> $posts
     */
    public function __construct(
        public readonly array $meta,
        public readonly array $forums,
        public readonly array $users,
        public readonly array $topics,
        public readonly array $posts,
    ) {
    }
}