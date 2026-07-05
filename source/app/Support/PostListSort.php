<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Topic post list sort modes (query param: sort).
 */
final class PostListSort
{
    public const OLDEST = 'oldest';
    public const NEWEST = 'newest';
    public const TOP = 'top';

    /** @var list<string> */
    private const ALLOWED = [
        self::OLDEST,
        self::NEWEST,
        self::TOP,
    ];

    public static function normalize(?string $raw): string
    {
        $sort = strtolower(trim((string) $raw));

        return in_array($sort, self::ALLOWED, true) ? $sort : self::OLDEST;
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    public static function sortPosts(array $posts, string $sort): array
    {
        $sort = self::normalize($sort);
        if ($sort === self::OLDEST || $posts === []) {
            return $posts;
        }

        $sorted = $posts;
        if ($sort === self::NEWEST) {
            usort(
                $sorted,
                static function (array $a, array $b): int {
                    $created = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                    if ($created !== 0) {
                        return $created;
                    }

                    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
                },
            );
        } else {
            usort(
                $sorted,
                static function (array $a, array $b): int {
                    $likes = ((int) ($b['like_count'] ?? 0)) <=> ((int) ($a['like_count'] ?? 0));
                    if ($likes !== 0) {
                        return $likes;
                    }

                    $created = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
                    if ($created !== 0) {
                        return $created;
                    }

                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                },
            );
        }

        return array_values($sorted);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::OLDEST => 'Oldest first',
            self::NEWEST => 'Newest first',
            self::TOP => 'Most likes',
        ];
    }

}