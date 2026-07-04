<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Plugins\MdImport\MarkdownImport;
use PHPUnit\Framework\TestCase;

final class MdImportTest extends TestCase
{
    private MarkdownImport $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownImport();
    }

    public function testParsesFrontMatterAndStripsMatchingH1(): void
    {
        $raw = <<<'MD'
---
title: Install guide
tags: docs, install
---

# Install guide

First paragraph.
MD;

        $parsed = $this->parser->parse($raw, 'install.md', null, true);

        $this->assertSame('Install guide', $parsed['title']);
        $this->assertSame(['docs', 'install'], $parsed['tags']);
        $this->assertSame("First paragraph.", $parsed['body']);
    }

    public function testSplitsLongMarkdownOnHeadings(): void
    {
        $body = "## One\n\n" . str_repeat('a', 100) . "\n\n## Two\n\n" . str_repeat('b', 100);
        $posts = $this->parser->splitIntoPosts($body, 120);

        $this->assertGreaterThan(1, count($posts));
        $this->assertStringStartsWith(MarkdownImport::MARKER, $posts[0]);
        $this->assertStringContainsString('## One', $posts[0]);
        $this->assertStringContainsString(str_repeat('b', 20), implode("\n", $posts));
    }

    public function testWrapsShortMarkdownWithMarker(): void
    {
        $posts = $this->parser->splitIntoPosts('# Hello', 65535);

        $this->assertCount(1, $posts);
        $this->assertSame(MarkdownImport::MARKER . "\n# Hello", $posts[0]);
    }
}