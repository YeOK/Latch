<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\PostFormatter;
use Latch\Import\Phpbb\BbcodeConverter;
use PHPUnit\Framework\TestCase;

final class PhpbbBbcodeConverterTest extends TestCase
{
    private BbcodeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeConverter(['customtag' => 'fenced']);
    }

    public function testBoldAndUrl(): void
    {
        $out = $this->converter->convert('[b]Hello[/b] — [url=https://latch.network]Latch[/url]');

        $this->assertStringContainsString('**Hello**', $out);
        $this->assertStringContainsString('[url=https://latch.network]Latch[/url]', $out);
    }

    public function testQuoteWithAuthor(): void
    {
        $out = $this->converter->convert('[quote="alice"]nested [i]text[/i][/quote]');

        $this->assertStringContainsString('[quote author="alice"]', $out);
        $this->assertStringContainsString('*text*', $out);
        $this->assertStringContainsString('[/quote]', $out);
    }

    public function testCodeBlock(): void
    {
        $out = $this->converter->convert('[code]echo hello;[/code]');

        $this->assertStringContainsString('[code]', $out);
        $this->assertStringContainsString('echo hello;', $out);
    }

    public function testListToBullets(): void
    {
        $out = $this->converter->convert("[list][*]alpha[*]beta[/list]");

        $this->assertStringContainsString('- alpha', $out);
        $this->assertStringContainsString('- beta', $out);
    }

    public function testStripsColorKeepsText(): void
    {
        $out = $this->converter->convert('[color=red]alert[/color]');

        $this->assertSame('alert', $out);
    }

    public function testCustomTagUsesConfiguredStrategy(): void
    {
        $out = $this->converter->convert('[customtag]secret[/customtag]');

        $this->assertStringContainsString('```', $out);
        $this->assertStringContainsString('secret', $out);
    }

    public function testConvertsToRenderableLatchMarkup(): void
    {
        $raw = $this->converter->convert('[b]Hi[/b] [url=https://example.com]link[/url]');
        $html = (new PostFormatter())->format($raw);

        $this->assertStringContainsString('<strong>Hi</strong>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }
}