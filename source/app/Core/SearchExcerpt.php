<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * Sanitizes FTS snippet() output for safe HTML display.
 *
 * Indexed post bodies are plain text but may contain literal angle brackets.
 * SQLite snippet() wraps matches in <mark>; we escape everything else.
 */
final class SearchExcerpt
{
    public static function sanitize(string $snippet): string
    {
        if ($snippet === '') {
            return '';
        }

        $escaped = htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['&lt;mark&gt;', '&lt;/mark&gt;'],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }
}