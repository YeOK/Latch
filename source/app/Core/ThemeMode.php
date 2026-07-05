<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Resolves user/guest light-dark theme preference.
 */
final class ThemeMode
{
    public const LIGHT = 'light';
    public const DARK = 'dark';
    public const SYSTEM = 'system';
    public const COOKIE = 'latch_theme';

    public static function normalizePreference(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return match ($mode) {
            self::LIGHT, self::DARK, self::SYSTEM => $mode,
            default => self::SYSTEM,
        };
    }

    public function preference(?array $user, ?string $cookie, string $siteDefault = self::SYSTEM): string
    {
        if ($user !== null && isset($user['theme_mode']) && $user['theme_mode'] !== '') {
            return self::normalizePreference((string) $user['theme_mode']);
        }

        if ($cookie !== null && $cookie !== '') {
            return self::normalizePreference($cookie);
        }

        return self::normalizePreference($siteDefault);
    }

    /**
     * Server-side effective palette for SSR (system defaults to light; client script corrects).
     */
    public function effective(string $preference): string
    {
        return match ($preference) {
            self::DARK => self::DARK,
            self::LIGHT => self::LIGHT,
            default => self::LIGHT,
        };
    }
}