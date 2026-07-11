<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\WordFilter;

/**
 * Prepare post text for scanning while preserving byte offsets into the original body.
 */
final class TextNormalizer
{
    /**
     * Return a same-length copy with fenced/inline code blanked so matchers keep stable indices.
     */
    public function scannableCopy(string $body): string
    {
        $copy = $body;
        $copy = $this->blankPattern($copy, '/```.*?```/s');
        $copy = $this->blankPattern($copy, '/`[^`]*`/');

        return $copy;
    }

    public function normalizeWord(string $word, bool $caseSensitive): string
    {
        $word = trim($word);
        if ($word === '') {
            return '';
        }

        return $caseSensitive ? $word : mb_strtolower($word, 'UTF-8');
    }

    public function normalizeChar(string $char, bool $caseSensitive): string
    {
        if ($char === '') {
            return '';
        }

        return $caseSensitive ? $char : mb_strtolower($char, 'UTF-8');
    }

    public function isWordChar(string $char): bool
    {
        if ($char === '') {
            return false;
        }

        return (bool) preg_match('/[\p{L}\p{N}_]/u', $char);
    }

    private function blankPattern(string $body, string $pattern): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static function (array $match): string {
                $segment = $match[0];
                $length = mb_strlen($segment, 'UTF-8');
                if ($length <= 0) {
                    return '';
                }

                return str_repeat(' ', $length);
            },
            $body,
        );
    }
}