<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


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

    public function testReplacesHtmlImgWithPlaceholderMarkdown(): void
    {
        $placeholder = 'https://images.example.test' . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
        $raw = '<p>Before</p><img src="https://old.site/photo.png" alt="Diagram"> After';

        $parsed = $this->parser->parse($raw, 'doc.md', 'Doc', false, $placeholder);

        $this->assertStringContainsString(
            '![Diagram (replace image)](' . $placeholder . ')',
            $parsed['body'],
        );
        $this->assertStringNotContainsString('<img', $parsed['body']);
    }

    public function testReplacesPictureBlockWithPlaceholderMarkdown(): void
    {
        $placeholder = 'https://images.example.test' . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
        $raw = <<<'MD'
# Title

<picture>
  <source srcset="https://old.site/hero-2x.jpg 2x">
  <img src="https://old.site/hero.jpg" alt="Hero banner">
</picture>

Text after.
MD;

        $parsed = $this->parser->parse($raw, 'doc.md', null, true, $placeholder);

        $this->assertStringContainsString(
            '![Hero banner (replace image)](' . $placeholder . ')',
            $parsed['body'],
        );
        $this->assertStringNotContainsString('<picture', $parsed['body']);
    }

    public function testRewritesForeignMarkdownImagesToPlaceholder(): void
    {
        $placeholder = 'https://images.example.test' . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
        $raw = 'See ![shot](https://other.example/a.png) here.';

        $parsed = $this->parser->parse($raw, 'doc.md', 'Doc', false, $placeholder);

        $this->assertSame(
            'See ![shot (replace image)](' . $placeholder . ') here.',
            $parsed['body'],
        );
    }

    public function testLeavesMarkdownImagesInCodeFencesUntouched(): void
    {
        $placeholder = 'https://images.example.test' . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
        $raw = "Docs:\n\n```html\n<img src=\"x.png\">\n```\n";

        $parsed = $this->parser->parse($raw, 'doc.md', 'Doc', false, $placeholder);

        $this->assertStringContainsString('<img src="x.png">', $parsed['body']);
    }
}