<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Builds RSS 2.0 XML documents.
 */
final class RssFeed
{
    /** @var list<array{title: string, link: string, guid: string, pub_date: string, description: string, author: string, categories: list<string>}> */
    private array $items = [];

    public function __construct(
        private readonly string $channelTitle,
        private readonly string $channelLink,
        private readonly string $channelDescription,
        private readonly string $selfLink,
    ) {
    }

    /**
     * @param list<string> $categories
     */
    public function addItem(
        string $title,
        string $link,
        string $guid,
        string $pubDateIso,
        string $description,
        string $author = '',
        array $categories = [],
    ): void {
        $this->items[] = [
            'title' => $title,
            'link' => $link,
            'guid' => $guid,
            'pub_date' => self::formatPubDate($pubDateIso),
            'description' => $description,
            'author' => $author,
            'categories' => $categories,
        ];
    }

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . self::escape($this->channelTitle) . "</title>\n";
        $xml .= '    <link>' . self::escape($this->channelLink) . "</link>\n";
        $xml .= '    <description>' . self::escape($this->channelDescription) . "</description>\n";
        $xml .= '    <language>en</language>' . "\n";
        $xml .= '    <lastBuildDate>' . self::escape(gmdate('D, d M Y H:i:s', time()) . ' GMT') . "</lastBuildDate>\n";
        $xml .= '    <atom:link href="' . self::escape($this->selfLink) . '" rel="self" type="application/rss+xml" />' . "\n";

        foreach ($this->items as $item) {
            $xml .= "    <item>\n";
            $xml .= '      <title>' . self::escape($item['title']) . "</title>\n";
            $xml .= '      <link>' . self::escape($item['link']) . "</link>\n";
            $xml .= '      <guid isPermaLink="true">' . self::escape($item['guid']) . "</guid>\n";
            $xml .= '      <pubDate>' . self::escape($item['pub_date']) . "</pubDate>\n";
            if ($item['author'] !== '') {
                $xml .= '      <author>' . self::escape($item['author']) . "</author>\n";
            }
            foreach ($item['categories'] as $category) {
                $xml .= '      <category>' . self::escape($category) . "</category>\n";
            }
            $xml .= '      <description><![CDATA[' . self::cdata($item['description']) . "]]></description>\n";
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function cdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }

    public static function formatPubDate(string $iso): string
    {
        $timestamp = strtotime($iso);

        if ($timestamp === false) {
            $timestamp = time();
        }

        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }

    public static function excerpt(string $text, int $maxLength = 400): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }
}