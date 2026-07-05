<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Parses @username mentions in post text (not email addresses).
 */
final class MentionParser
{
    /** Username: 3–32 chars, same rules as registration. */
    private const PATTERN = '/(?<![a-zA-Z0-9])@([a-zA-Z0-9][a-zA-Z0-9_-]{2,31})(?![a-zA-Z0-9_-])/';

    /**
     * @return list<string>
     */
    public function usernames(string $text): array
    {
        if (!preg_match_all(self::PATTERN, $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Linkify @mentions in already HTML-escaped inline text.
     */
    public function linkifyEscaped(string $escapedText): string
    {
        return preg_replace_callback(
            self::PATTERN,
            static function (array $match): string {
                $username = $match[1];
                $safe = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="/user/' . $safe . '" class="mention">@' . $safe . '</a>';
            },
            $escapedText,
        ) ?? $escapedText;
    }
}