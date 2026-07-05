<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use DOMDocument;
use Latch\Core\Sitemap;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    public function testRenderProducesValidSitemapDocument(): void
    {
        $sitemap = new Sitemap();
        $sitemap->addUrl('https://latch.network/', '2026-06-30T08:00:00+00:00', 'daily', '1.0');
        $sitemap->addUrl('https://latch.network/topic/1', '2026-06-29T12:00:00+00:00', 'weekly', '0.6');

        $xml = $sitemap->render();
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'Sitemap XML should parse');

        $urls = $doc->getElementsByTagName('url');
        $this->assertSame(2, $urls->length);

        $home = $urls->item(0);
        $this->assertNotNull($home);
        $this->assertSame('https://latch.network/', $home->getElementsByTagName('loc')->item(0)?->textContent);
        $this->assertSame('2026-06-30', $home->getElementsByTagName('lastmod')->item(0)?->textContent);
        $this->assertSame('daily', $home->getElementsByTagName('changefreq')->item(0)?->textContent);
        $this->assertSame('1.0', $home->getElementsByTagName('priority')->item(0)?->textContent);
    }

    public function testFormatLastmodFallsBackToToday(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            Sitemap::formatLastmod('not-a-date'),
        );
    }
}