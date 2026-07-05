<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use PHPUnit\Framework\TestCase;

final class LocaleCatalogTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    public function testAllLocalesMatchEnglishKeyCatalog(): void
    {
        $langPath = dirname(__DIR__) . '/lang';
        $en = self::flatten(require $langPath . '/en.php');

        foreach (['es', 'de', 'fr', 'ar'] as $locale) {
            $other = self::flatten(require $langPath . '/' . $locale . '.php');
            $this->assertSame(
                array_keys($en),
                array_keys($other),
                'Locale ' . $locale . ' key catalog must match en.php',
            );
        }
    }

    public function testArabicCatalogIsRtl(): void
    {
        $locale = new \Latch\Core\Locale();
        $this->assertSame('rtl', $locale->direction('ar'));
        $this->assertSame('ltr', $locale->direction('en'));
    }
}