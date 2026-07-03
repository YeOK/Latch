<?php

declare(strict_types=1);

namespace Latch\Tests;

use DOMDocument;
use Latch\Core\RssFeed;
use PHPUnit\Framework\TestCase;

final class RssFeedTest extends TestCase
{
    public function testEscapeXmlSpecialCharacters(): void
    {
        $this->assertSame(
            '&lt;tag attr=&quot;x&quot;&gt; &amp; &apos;end&apos;',
            RssFeed::escape('<tag attr="x"> & \'end\''),
        );
    }

    public function testCdataEscapesTerminator(): void
    {
        $this->assertSame('before]]]]><![CDATA[>after', RssFeed::cdata('before]]>after'));
    }

    public function testExcerptTruncatesLongText(): void
    {
        $text = str_repeat('word ', 100);
        $excerpt = RssFeed::excerpt($text, 40);

        $this->assertLessThanOrEqual(40, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function testRenderProducesValidRssDocument(): void
    {
        $feed = new RssFeed(
            'Latch Test',
            'https://example.test/',
            'Test forum feed',
            'https://example.test/feed.xml',
        );
        $feed->addItem(
            'Hello & welcome',
            'https://example.test/topic/1',
            'https://example.test/topic/1',
            '2026-06-29T12:00:00+00:00',
            'First post with <markup> & more',
            'yeok',
            ['news', 'general'],
        );

        $xml = $feed->render();
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'RSS XML should parse');

        $channels = $doc->getElementsByTagName('channel');
        $this->assertSame(1, $channels->length);

        $channel = $channels->item(0);
        $this->assertNotNull($channel);
        $this->assertSame('Latch Test', $channel->getElementsByTagName('title')->item(0)?->textContent);

        $items = $doc->getElementsByTagName('item');
        $this->assertSame(1, $items->length);

        $item = $items->item(0);
        $this->assertNotNull($item);
        $this->assertSame('Hello & welcome', $item->getElementsByTagName('title')->item(0)?->textContent);
        $this->assertSame('yeok', $item->getElementsByTagName('author')->item(0)?->textContent);

        $categories = [];
        foreach ($item->getElementsByTagName('category') as $category) {
            $categories[] = $category->textContent;
        }
        $this->assertSame(['news', 'general'], $categories);

        $description = $item->getElementsByTagName('description')->item(0)?->textContent;
        $this->assertStringContainsString('First post with <markup> & more', (string) $description);
    }

    public function testFormatPubDateUsesRfc822Gmt(): void
    {
        $this->assertSame(
            'Mon, 29 Jun 2026 12:00:00 GMT',
            RssFeed::formatPubDate('2026-06-29T12:00:00+00:00'),
        );
    }
}