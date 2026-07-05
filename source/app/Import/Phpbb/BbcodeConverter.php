<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Import\Phpbb;

/**
 * Converts phpBB 3.3 BBCode to Latch native post markup.
 */
final class BbcodeConverter
{
    /** @var list<string> */
    private const HANDLED_TAGS = [
        'b', 'i', 'u', 'url', 'img', 'quote', 'code', 'list',
        'size', 'color', 'font', 'center', 'right', 'left', 'hr', 'spacer',
        'youtube', 'video', 'media',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string, string> $customStrategies tag name (lower) => strip|fenced|keep
     */
    public function __construct(
        private readonly array $customStrategies = [],
    ) {
    }

    public function convert(string $bbcode): string
    {
        $this->warnings = [];
        $text = str_replace(["\r\n", "\r"], "\n", trim($bbcode));
        if ($text === '') {
            return '';
        }

        $text = $this->convertCodeBlocks($text);
        $text = $this->convertQuotes($text);
        $text = $this->convertLists($text);
        $text = $this->convertInlineTags($text);
        $text = $this->stripUnknownTags($text);

        return trim($text);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function convertCodeBlocks(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[code(?:=([^\]]*))?\](.*?)\[\/code\]/si',
            function (array $m): string {
                $lang = trim($m[1]);
                $body = $m[2];
                if ($lang !== '') {
                    return "```{$lang}\n{$body}\n```";
                }

                return "[code]\n{$body}\n[/code]";
            },
            $text,
        );
    }

    private function convertQuotes(string $text): string
    {
        $limit = 50;
        while ($limit-- > 0 && preg_match('/\[quote(?:="([^"]+)"|=([^"\]]+))?\](.*?)\[\/quote\]/si', $text, $m)) {
            $author = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
            $inner = $this->convertInlineTags($this->convertLists($m[3]));
            $inner = $this->stripUnknownTags($inner);
            $open = $author !== '' ? '[quote author="' . $author . '"]' : '[quote]';

            $text = (string) preg_replace(
                '/\[quote(?:="([^"]+)"|=([^"\]]+))?\](.*?)\[\/quote\]/si',
                $open . "\n" . $inner . "\n[/quote]",
                $text,
                1,
            );
        }

        return $text;
    }

    private function convertLists(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[list(?:=[^\]]*)?\](.*?)\[\/list\]/si',
            function (array $m): string {
                $inner = (string) preg_replace('/\[\*\]\s*/', "\n", $m[1]);
                $lines = preg_split('/\n/', trim($inner)) ?: [];
                $items = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $items[] = '- ' . $line;
                }

                return implode("\n", $items);
            },
            $text,
        );
    }

    private function convertInlineTags(string $text): string
    {
        $pairs = [
            '/\[b\](.*?)\[\/b\]/si' => '**$1**',
            '/\[i\](.*?)\[\/i\]/si' => '*$1*',
            '/\[u\](.*?)\[\/u\]/si' => '$1',
            '/\[url=(https?:\/\/[^\]]+)\](.*?)\[\/url\]/si' => '[url=$1]$2[/url]',
            '/\[url\](https?:\/\/[^\[]+)\[\/url\]/si' => '[url]$1[/url]',
            '/\[img\](https?:\/\/[^\[]+)\[\/img\]/si' => '$1',
        ];

        foreach ($pairs as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        $text = (string) preg_replace_callback(
            '/\[img\]([^\[]+)\[\/img\]/si',
            function (array $m): string {
                $this->warnings[] = 'Blocked non-HTTPS image: ' . substr(trim($m[1]), 0, 80);

                return '[image blocked]';
            },
            $text,
        );

        $stripTags = ['size', 'color', 'font', 'center', 'right', 'left', 'hr', 'spacer'];
        foreach ($stripTags as $tag) {
            $text = (string) preg_replace('/\[' . $tag . '(?:=[^\]]*)?\](.*?)\[\/' . $tag . '\]/si', '$1', $text);
        }

        $text = (string) preg_replace_callback(
            '/\[(youtube|video|media)(?:=[^\]]*)?\](.*?)\[\/\1\]/si',
            function (array $m): string {
                $url = trim($m[2]);
                if (preg_match('#^https?://#i', $url) === 1) {
                    return $url;
                }

                return $url;
            },
            $text,
        );

        return $text;
    }

    private function stripUnknownTags(string $text): string
    {
        $handled = implode('|', self::HANDLED_TAGS);
        $limit = 100;
        $unknownPattern = '/\[(?!\/)(?!(?:' . $handled . ')\b)([a-z][a-z0-9]*)(?:=[^\]]*)?\](.*?)\[\/\1\]/si';
        while ($limit-- > 0 && preg_match($unknownPattern, $text, $m)) {
            $tag = strtolower($m[1]);
            $inner = $m[2];

            $strategy = $this->customStrategies[$tag] ?? 'strip';
            $replacement = match ($strategy) {
                'fenced' => "```\n{$inner}\n```",
                'keep' => $m[0],
                default => $inner,
            };

            if ($strategy === 'strip') {
                $this->warnings[] = "Stripped unknown BBCode tag [{$tag}]";
            }

            $text = (string) preg_replace(
                '/\[(?!\/)(?!(?:' . $handled . ')\b)' . preg_quote($tag, '/') . '(?:=[^\]]*)?\](.*?)\[\/' . preg_quote($tag, '/') . '\]/si',
                $replacement,
                $text,
                1,
            );
        }

        $text = (string) preg_replace('/\[(?!\/)(?!(?:' . $handled . ')\b)[a-z][a-z0-9]*(?:=[^\]]*)?\]/i', '', $text);
        $text = (string) preg_replace('/\[\/(?!(?:' . $handled . ')\b)[a-z][a-z0-9]*\]/i', '', $text);

        return $text;
    }
}