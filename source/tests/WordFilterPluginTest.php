<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Plugins\WordFilter\Settings;
use Latch\Plugins\WordFilter\TextNormalizer;
use Latch\Plugins\WordFilter\WordFilter;
use Latch\Plugins\WordFilter\WordMatcher;
use PHPUnit\Framework\TestCase;

final class WordFilterPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->pluginDir = CatalogPath::plugin('word-filter');
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);

        $this->assertTrue($report->passed(), $report->toHuman());
        $this->assertSame(0, $report->warnCount());
    }

    public function testBlocksBundledWordInBody(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = $this->context('What a shit show.');

        $this->assertSame('Your post contains language that is not allowed on this forum.', $filter->process($ctx));
    }

    public function testAllowsCleanBody(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = $this->context('Hello world.');

        $this->assertNull($filter->process($ctx));
    }

    public function testIgnoresInlineCode(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = $this->context('Docs mention `shit` as an example token only.');

        $this->assertNull($filter->process($ctx));
    }

    public function testIgnoresFencedCode(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = $this->context("Before\n```\nshit\n```\nAfter");

        $this->assertNull($filter->process($ctx));
    }

    public function testStaffBypass(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = $this->context('Still shit.', user: ['id' => 2, 'role' => 'mod']);

        $this->assertNull($filter->process($ctx));
    }

    public function testBlocksTopicTitle(): void
    {
        $filter = $this->filterWithWords(['shit']);
        $ctx = new PostSaveContext(
            body: 'Clean body',
            user: ['id' => 3, 'role' => 'member'],
            board: ['id' => 1],
            topic: null,
            kind: 'topic',
            topicTitle: 'This is shit',
        );

        $this->assertSame('Your post contains language that is not allowed on this forum.', $filter->process($ctx));
    }

    public function testMaskModeReplacesBodyWords(): void
    {
        $filter = $this->filterWithMode(Settings::MODE_MASK, ['shit']);
        $ctx = $this->context('What shit timing.');

        $this->assertNull($filter->process($ctx));
        $this->assertSame('What **** timing.', $ctx->body);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $filter = $this->filterWithWords(['shit'], caseSensitive: false);
        $ctx = $this->context('SHIT happens.');

        $this->assertSame('Your post contains language that is not allowed on this forum.', $filter->process($ctx));
    }

    public function testWordBoundaryAvoidsSubstringFalsePositive(): void
    {
        $matcher = new WordMatcher(new TextNormalizer(), false, ['ass']);
        $matches = $matcher->findAll('class assignment');

        $this->assertSame([], $matches);
    }

    public function testMatcherFindsMultipleWordsInOnePass(): void
    {
        $matcher = new WordMatcher(new TextNormalizer(), false, ['foo', 'bar', 'baz']);
        $matches = $matcher->findAll('foo bar and baz');

        $this->assertCount(3, $matches);
    }

    public function testLoadsBundledWordList(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $storageRoot = sys_get_temp_dir() . '/latch-word-filter-empty-' . bin2hex(random_bytes(4));
        $settings = Settings::load($this->pluginDir, $storageRoot, $manifest);

        $this->assertContains('shit', $settings->blockedWords);
        $this->assertSame(Settings::MODE_BLOCK, $settings->mode);
    }

    /**
     * @param list<string> $words
     */
    private function filterWithWords(array $words, bool $caseSensitive = false): WordFilter
    {
        return $this->filterWithMode(Settings::MODE_BLOCK, $words, $caseSensitive);
    }

    /**
     * @param list<string> $words
     */
    private function filterWithMode(string $mode, array $words, bool $caseSensitive = false): WordFilter
    {
        $settings = new Settings(
            mode: $mode,
            caseSensitive: $caseSensitive,
            staffBypass: true,
            applyTo: ['body', 'topic_title'],
            blockedWords: $words,
        );

        return new WordFilter($settings);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function context(string $body, array $user = ['id' => 1, 'role' => 'member']): PostSaveContext
    {
        return new PostSaveContext(
            body: $body,
            user: $user,
            board: ['id' => 1],
            topic: null,
            kind: 'reply',
        );
    }
}