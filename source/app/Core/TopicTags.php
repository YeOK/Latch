<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Support\Str;
use RuntimeException;

/**
 * Parse and validate topic tag input (comma-separated labels).
 */
final class TopicTags
{
    public const MAX_NAME_LENGTH = 32;
    public const MAX_SLUG_LENGTH = 40;

    /**
     * @return list<string> Display names (deduped, case-insensitive)
     */
    public function parse(string $raw, int $maxTags): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $seen = [];
        $tags = [];

        foreach ($parts as $part) {
            $name = trim($part);
            if ($name === '') {
                continue;
            }

            if (strlen($name) > self::MAX_NAME_LENGTH) {
                throw new RuntimeException('Each tag must be ' . self::MAX_NAME_LENGTH . ' characters or fewer.');
            }

            if (!preg_match('/^[\p{L}\p{N}\s\-]+$/u', $name)) {
                throw new RuntimeException('Tags may only contain letters, numbers, spaces, and hyphens.');
            }

            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[] = $name;

            if (count($tags) > $maxTags) {
                throw new RuntimeException("A topic may have at most {$maxTags} tags.");
            }
        }

        return $tags;
    }

    public function slugForName(string $name): string
    {
        return Str::slug($name, self::MAX_SLUG_LENGTH);
    }
}