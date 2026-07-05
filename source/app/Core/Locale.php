<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Resolves guest/member locale and RTL layout direction.
 */
final class Locale
{
    public const COOKIE = 'latch_locale';
    public const DEFAULT = 'en';

    /** @var array<string, array{name: string, native: string, rtl: bool}> */
    private const CATALOG = [
        'en' => ['name' => 'English', 'native' => 'English', 'rtl' => false],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'rtl' => false],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'rtl' => false],
        'fr' => ['name' => 'French', 'native' => 'Français', 'rtl' => false],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'rtl' => true],
    ];

    public static function normalize(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));
        if ($code === '') {
            return self::DEFAULT;
        }

        $primary = explode('-', $code)[0];

        return array_key_exists($primary, self::CATALOG) ? $primary : self::DEFAULT;
    }

    /**
     * @return array<string, array{name: string, native: string, rtl: bool}>
     */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::CATALOG);
    }

    public function preference(?array $user, ?string $cookie, string $siteDefault = self::DEFAULT): string
    {
        if ($user !== null && isset($user['locale']) && (string) $user['locale'] !== '') {
            return self::normalize((string) $user['locale']);
        }

        if ($cookie !== null && $cookie !== '') {
            return self::normalize($cookie);
        }

        return self::normalize($siteDefault);
    }

    public function direction(string $locale): string
    {
        return (self::CATALOG[self::normalize($locale)]['rtl'] ?? false) ? 'rtl' : 'ltr';
    }
}