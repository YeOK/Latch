<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Board topic list sort modes (query param: sort).
 */
final class TopicListSort
{
    public const ACTIVITY = 'activity';
    public const NEWEST = 'newest';
    public const OLDEST = 'oldest';
    public const REPLIES = 'replies';
    public const UNREAD = 'unread';

    /** @var list<string> */
    private const ALLOWED = [
        self::ACTIVITY,
        self::NEWEST,
        self::OLDEST,
        self::REPLIES,
        self::UNREAD,
    ];

    public static function normalize(?string $raw): string
    {
        $sort = strtolower(trim((string) $raw));

        return in_array($sort, self::ALLOWED, true) ? $sort : self::ACTIVITY;
    }

    public static function orderBySql(string $sort): string
    {
        return match (self::normalize($sort)) {
            self::NEWEST => 't.is_pinned DESC, t.created_at DESC',
            self::OLDEST => 't.is_pinned DESC, t.last_post_at ASC',
            self::REPLIES => 't.is_pinned DESC, post_count DESC, t.last_post_at DESC',
            default => 't.is_pinned DESC, t.last_post_at DESC',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ACTIVITY => 'Latest activity',
            self::NEWEST => 'Newest topics',
            self::OLDEST => 'Oldest activity',
            self::REPLIES => 'Most replies',
            self::UNREAD => 'Unread first',
        ];
    }

}