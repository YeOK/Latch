<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Builds sitemap.xml documents.
 */
final class Sitemap
{
    /** @var list<array{loc: string, lastmod: ?string, changefreq: ?string, priority: ?string}> */
    private array $urls = [];

    public function addUrl(
        string $loc,
        ?string $lastmodIso = null,
        ?string $changefreq = null,
        ?string $priority = null,
    ): void {
        $this->urls[] = [
            'loc' => $loc,
            'lastmod' => $lastmodIso !== null ? self::formatLastmod($lastmodIso) : null,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($this->urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . RssFeed::escape($url['loc']) . "</loc>\n";
            if ($url['lastmod'] !== null) {
                $xml .= '    <lastmod>' . RssFeed::escape($url['lastmod']) . "</lastmod>\n";
            }
            if ($url['changefreq'] !== null) {
                $xml .= '    <changefreq>' . RssFeed::escape($url['changefreq']) . "</changefreq>\n";
            }
            if ($url['priority'] !== null) {
                $xml .= '    <priority>' . RssFeed::escape($url['priority']) . "</priority>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    public static function formatLastmod(string $iso): string
    {
        $timestamp = strtotime($iso);

        if ($timestamp === false) {
            return gmdate('Y-m-d');
        }

        return gmdate('Y-m-d', $timestamp);
    }
}