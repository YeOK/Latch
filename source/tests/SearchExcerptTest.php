<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\SearchExcerpt;
use PHPUnit\Framework\TestCase;

final class SearchExcerptTest extends TestCase
{
    public function testEscapesHtmlButPreservesMarkTags(): void
    {
        $snippet = '<img src=x onerror=alert(1)> <mark>match</mark> end';
        $safe = SearchExcerpt::sanitize($snippet);

        $this->assertStringContainsString('<mark>match</mark>', $safe);
        $this->assertStringNotContainsString('<img', $safe);
        $this->assertStringContainsString('&lt;img', $safe);
    }

    public function testEmptySnippetReturnsEmpty(): void
    {
        $this->assertSame('', SearchExcerpt::sanitize(''));
    }
}