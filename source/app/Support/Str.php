<?php

declare(strict_types=1);

namespace Latch\Support;

/**
 * String helpers for URLs and slugs.
 */
final class Str
{
    public static function slug(string $value, int $maxLength = 80): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'item';
        }

        if (strlen($slug) > $maxLength) {
            $slug = rtrim(substr($slug, 0, $maxLength), '-');
        }

        return $slug;
    }

    public static function uniqueSlug(string $base, callable $exists): string
    {
        $slug = self::slug($base);
        $candidate = $slug;
        $suffix = 2;

        while ($exists($candidate)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}