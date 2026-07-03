<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\SeoMeta;
use PHPUnit\Framework\TestCase;

final class SeoMetaTest extends TestCase
{
    public function testForTopicBuildsArticleMetadata(): void
    {
        $seo = SeoMeta::forTopic(
            'https://latch.network',
            'Latch',
            'Hello world',
            42,
            'First post excerpt goes here.',
            '2026-06-29T10:00:00+00:00',
        )->toArray();

        $this->assertSame('Hello world — Latch', $seo['title']);
        $this->assertSame('https://latch.network/topic/42', $seo['canonical']);
        $this->assertSame('article', $seo['type']);
        $this->assertSame('2026-06-29T10:00:00+00:00', $seo['published_time']);
        $this->assertStringContainsString('og-image.png', (string) $seo['image']);
        $this->assertSame(1200, $seo['image_width']);
        $this->assertSame(630, $seo['image_height']);
        $this->assertSame('image/png', $seo['image_type']);
        $this->assertFalse($seo['noindex']);
    }

    public function testPathRequiresNoindexForPrivateAreas(): void
    {
        $this->assertTrue(SeoMeta::pathRequiresNoindex('/login'));
        $this->assertTrue(SeoMeta::pathRequiresNoindex('/admin/users'));
        $this->assertTrue(SeoMeta::pathRequiresNoindex('/board/general/new'));
        $this->assertFalse(SeoMeta::pathRequiresNoindex('/board/general'));
        $this->assertFalse(SeoMeta::pathRequiresNoindex('/topic/1'));
    }

    public function testMembersOnlyForcesNoindexOnHome(): void
    {
        $seo = SeoMeta::forHome('https://latch.network', 'Latch', 'Fast forum', true)->toArray();

        $this->assertTrue($seo['noindex']);
    }

    public function testDescriptionIsTrimmed(): void
    {
        $long = str_repeat('word ', 80);
        $seo = SeoMeta::forBoard(
            'https://latch.network',
            'Latch',
            ['name' => 'General', 'description' => $long],
            '/board/general',
        )->toArray();

        $this->assertLessThanOrEqual(SeoMeta::DESCRIPTION_MAX, mb_strlen((string) $seo['description']));
    }
}