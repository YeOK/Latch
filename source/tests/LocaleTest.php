<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Locale;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testNormalizeFallsBackToEnglish(): void
    {
        $this->assertSame('en', Locale::normalize(''));
        $this->assertSame('en', Locale::normalize('zz'));
        $this->assertSame('es', Locale::normalize('es-ES'));
    }

    public function testPreferenceOrderUserCookieSite(): void
    {
        $locale = new Locale();
        $user = ['locale' => 'es'];

        $this->assertSame('es', $locale->preference($user, 'de', 'en'));
        $this->assertSame('de', $locale->preference(null, 'de', 'en'));
        $this->assertSame('fr', $locale->preference(null, null, 'fr'));
    }

    public function testDirectionForArabic(): void
    {
        $locale = new Locale();
        $this->assertSame('rtl', $locale->direction('ar'));
        $this->assertSame('ltr', $locale->direction('en'));
    }
}