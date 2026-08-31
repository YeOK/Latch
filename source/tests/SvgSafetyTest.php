<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SvgSafety;
use PHPUnit\Framework\TestCase;

final class SvgSafetyTest extends TestCase
{
    public function testAllowsPlainPathSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10H0z"/></svg>';

        $this->assertFalse(SvgSafety::containsDisallowedMarkup($svg));
    }

    public function testBlocksPointerAndSmilHandlers(): void
    {
        $this->assertTrue(SvgSafety::containsDisallowedMarkup('<svg onpointerdown="alert(1)"></svg>'));
        $this->assertTrue(SvgSafety::containsDisallowedMarkup('<svg onbegin="alert(1)"></svg>'));
        $this->assertTrue(SvgSafety::containsDisallowedMarkup('<svg onfocusin="alert(1)"></svg>'));
    }

    public function testBlocksScriptAndJavascriptUrl(): void
    {
        $this->assertTrue(SvgSafety::containsDisallowedMarkup('<svg><script>alert(1)</script></svg>'));
        $this->assertTrue(SvgSafety::containsDisallowedMarkup('<a href="javascript:alert(1)">x</a>'));
    }
}
