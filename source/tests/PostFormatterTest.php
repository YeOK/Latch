<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\PostFormatter;
use PHPUnit\Framework\TestCase;

final class PostFormatterTest extends TestCase
{
    private PostFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new PostFormatter();
    }

    public function testWrapsImportedMarkdownWithGithubClass(): void
    {
        $html = $this->formatter->format("<!-- latch-md-import -->\n# Hello\n\nParagraph.");

        $this->assertStringStartsWith('<div class="post-md-import">', $html);
        $this->assertStringContainsString('<h2 class="post-heading">Hello</h2>', $html);
        $this->assertStringEndsWith('</div>', $html);
    }

    public function testRendersMarkdownLink(): void
    {
        $html = $this->formatter->format(
            'Visit [forum.example.com](https://forum.example.com) for details.',
        );

        $this->assertStringContainsString(
            '<a href="https://forum.example.com" rel="nofollow ugc" target="_blank">forum.example.com</a>',
            $html,
        );
    }

    public function testRendersMarkdownRelativeLink(): void
    {
        $html = $this->formatter->format('See [@mentions](/user/mentions) docs.');

        $this->assertStringContainsString('href="/user/mentions"', $html);
        $this->assertStringContainsString('@mentions', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    public function testBlocksMarkdownImageWithoutHostAllowlist(): void
    {
        $html = $this->formatter->format('![x](https://evil.example/x.png)');

        $this->assertStringContainsString('[image blocked]', $html);
        $this->assertStringNotContainsString('<img ', $html);
    }

    public function testRendersSmileyShortcodes(): void
    {
        $html = $this->formatter->format('Hello :smile: and :thumbsup:');

        $this->assertStringContainsString('class="smiley"', $html);
        $this->assertStringContainsString('😊', $html);
        $this->assertStringContainsString('👍', $html);
    }

    public function testRendersBlockSpoiler(): void
    {
        $html = $this->formatter->format("[spoiler=\"Ending\"]\nIt was all a dream.\n[/spoiler]");

        $this->assertStringContainsString('<details class="post-spoiler">', $html);
        $this->assertStringContainsString('<summary>Ending</summary>', $html);
        $this->assertStringContainsString('It was all a dream.', $html);
    }

    public function testRendersInlineSpoiler(): void
    {
        $html = $this->formatter->format('Answer: [spoiler]42[/spoiler]!');

        $this->assertStringContainsString('post-spoiler-inline', $html);
        $this->assertStringContainsString('<summary>Spoiler</summary>', $html);
        $this->assertStringContainsString('42', $html);
    }

    public function testPlainTextStripsSpoilerTags(): void
    {
        $text = $this->formatter->plainText('[spoiler]secret[/spoiler]');

        $this->assertStringContainsString('secret', $text);
        $this->assertStringNotContainsString('[spoiler]', $text);
    }

    public function testPlainTextStripsAnnouncementMarker(): void
    {
        $text = $this->formatter->plainText("<!-- latch-announcement:2026-06-30-about -->\nWhat is Latch?");

        $this->assertSame('What is Latch?', $text);
    }

    public function testSmileysListIsOrderedForPicker(): void
    {
        $smileys = PostFormatter::smileys();

        $this->assertSame(':smile:', array_key_first($smileys));
        $this->assertGreaterThanOrEqual(12, count($smileys));
    }

    public function testRendersCitationStyleCodeFence(): void
    {
        $html = $this->formatter->format(
            "```233:235:app/Core/Plugins/PluginAuditor.php\n"
            . "if (!str_ends_with(\$relative, '.php')) {\n"
            . "    continue;\n"
            . "}\n"
            . '```',
        );

        $this->assertStringContainsString('<pre class="code-block"', $html);
        $this->assertStringContainsString('str_ends_with', $html);
        $this->assertStringNotContainsString('```233', $html);
    }

    public function testRendersMarkdownTable(): void
    {
        $html = $this->formatter->format(
            "| Code | Message |\n"
            . "|------|--------|\n"
            . '| `markup_script_tag` | `<script>` tag |',
        );

        $this->assertStringContainsString('<table class="post-table">', $html);
        $this->assertStringContainsString('<th>', $html);
        $this->assertStringContainsString('markup_script_tag', $html);
        $this->assertStringNotContainsString('```', $html);
    }

    public function testRendersMarkdownHeading(): void
    {
        $html = $this->formatter->format("## Overview\n\nParagraph text.");

        $this->assertStringContainsString('<h3 class="post-heading">Overview</h3>', $html);
        $this->assertStringContainsString('<p>Paragraph text.</p>', $html);
    }

    public function testRendersLanguageCodeFence(): void
    {
        $html = $this->formatter->format("```php\n<?php echo 'hi';\n```");

        $this->assertStringContainsString('<pre class="code-block" data-lang="php">', $html);
        $this->assertStringContainsString('class="language-php"', $html);
        $this->assertStringContainsString('echo &#039;hi&#039;', $html);
    }

    public function testStandaloneBareHttpsUrlAutolinks(): void
    {
        $html = $this->formatter->format('https://example.test/page');

        $this->assertStringContainsString('<a href="https://example.test/page"', $html);
    }

    public function testStandalonePluginLinkCardIsNotWrappedInParagraph(): void
    {
        $formatter = new PostFormatter();
        $formatter->setLinkFormatter(
            static fn (string $html, string $url, string $label, bool $standalone): string => $standalone
                ? '<aside class="link-onebox"><a href="' . $url . '">card</a></aside>'
                : $html,
        );

        $html = $formatter->format('https://example.test/article');

        $this->assertStringContainsString('<aside class="link-onebox">', $html);
        $this->assertStringNotContainsString('<p><aside', $html);
        $this->assertStringNotContainsString('</aside></p>', $html);
    }

    public function testInlineBareHttpsUrlIsNotAutolinked(): void
    {
        $html = $this->formatter->format('Visit https://example.test/page today.');

        $this->assertStringNotContainsString('<a href=', $html);
        $this->assertStringContainsString('https://example.test/page', $html);
    }

    public function testRendersIndentedCodeFence(): void
    {
        $html = $this->formatter->format(
            "1. Gate failed:\n"
            . "     ```\n"
            . "     Refusing restore\n"
            . "     ```\n"
            . "2. Continue",
        );

        $this->assertStringContainsString('<pre class="code-block">', $html);
        $this->assertStringContainsString('Refusing restore', $html);
        $this->assertStringNotContainsString('```', $html);
    }

    public function testComposerPreviewSkipsImagesAndPluginLinkCards(): void
    {
        $formatter = new PostFormatter();
        $formatter->setImageHostChecker(static fn (string $host): bool => $host === 'cdn.example.com');
        $formatter->setLinkFormatter(
            static fn (string $html, string $url, string $label, bool $standalone): string => $standalone
                ? '<div class="plugin-card">card</div>'
                : $html,
        );

        $imageHtml = $formatter->format('![screenshot](https://cdn.example.com/a.png)', true);
        $this->assertStringContainsString('composer-preview-placeholder', $imageHtml);
        $this->assertStringContainsString('[image: screenshot]', $imageHtml);
        $this->assertStringNotContainsString('<img ', $imageHtml);

        $linkHtml = $formatter->format('https://example.test/watch?v=abc', true);
        $this->assertStringContainsString('<a href="https://example.test/watch?v=abc"', $linkHtml);
        $this->assertStringNotContainsString('plugin-card', $linkHtml);

        $smileyHtml = $formatter->format('Hello :smile:', true);
        $this->assertStringContainsString('class="smiley"', $smileyHtml);
        $this->assertStringContainsString('😊', $smileyHtml);
    }
}