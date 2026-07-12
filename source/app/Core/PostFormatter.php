<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Converts raw post markup to safe HTML (allowlist only).
 */
final class PostFormatter
{
    private readonly MentionParser $mentions;

    /** @var (callable(string): bool)|null */
    private $imageHostAllowed = null;

    /** @var (callable(string, string, string, bool): string)|null */
    private $linkFormatter = null;

    /** @var (callable(string, string): string)|null */
    private $formatAfterFilter = null;

    private bool $composerPreview = false;

    /** @var array<string, string> Most-used first — picker order matches this list. */
    private const SMILEYS = [
        ':smile:' => '😊',
        ':thumbsup:' => '👍',
        ':laugh:' => '😂',
        ':rofl:' => '🤣',
        ':heart:' => '❤️',
        ':fire:' => '🔥',
        ':wink:' => '😉',
        ':thinking:' => '🤔',
        ':sad:' => '😢',
        ':cool:' => '😎',
        ':eyes:' => '👀',
        ':clap:' => '👏',
        ':party:' => '🎉',
        ':100:' => '💯',
        ':skull:' => '💀',
        ':sparkles:' => '✨',
    ];

    /** @return array<string, string> */
    public static function smileys(): array
    {
        return self::SMILEYS;
    }

    public function __construct(?MentionParser $mentions = null)
    {
        $this->mentions = $mentions ?? new MentionParser();
    }

    /** @param callable(string): bool $checker */
    public function setImageHostChecker(callable $checker): void
    {
        $this->imageHostAllowed = $checker;
    }

    /** @param callable(string, string, string, bool): string $formatter */
    public function setLinkFormatter(callable $formatter): void
    {
        $this->linkFormatter = $formatter;
    }

    /** @param callable(string, string): string $filter */
    public function setFormatAfterFilter(callable $filter): void
    {
        $this->formatAfterFilter = $filter;
    }

    public function plainText(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = preg_replace('/^<!-- latch-announcement:[a-z0-9-]+ -->\s*/', '', $raw) ?? $raw;
        if ($raw === '') {
            return '';
        }

        $blocks = $this->splitBlocks($raw);
        $parts = [];

        foreach ($blocks as $block) {
            $parts[] = match ($block['type']) {
                'code' => trim($block['content']),
                'quote' => $this->plainInline(trim($block['content'])),
                'text' => $this->plainInline($block['content']),
                default => '',
            };
        }

        $text = trim(implode("\n", $parts));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }

    public function format(string $raw, bool $composerPreview = false): string
    {
        $previousPreview = $this->composerPreview;
        $this->composerPreview = $composerPreview;

        try {
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $mdImport = false;
            if (str_starts_with($raw, '<!-- latch-md-import -->')) {
                $mdImport = true;
                $raw = (string) preg_replace('/^<!-- latch-md-import -->\s*/', '', $raw);
            }
            $raw = preg_replace('/^<!-- latch-announcement:[a-z0-9-]+ -->\s*/', '', $raw) ?? $raw;
            if ($raw === '') {
                return '';
            }

            $blocks = $this->splitBlocks($raw);
            $html = '';

            foreach ($blocks as $block) {
                $html .= match ($block['type']) {
                    'code' => $this->renderCodeBlock($block['lang'], $block['content']),
                    'quote' => $this->renderQuote($block['author'], $block['content']),
                    'text' => $this->formatTextBlock($block['content']),
                    default => '',
                };
            }

            if ($this->formatAfterFilter !== null) {
                $html = ($this->formatAfterFilter)($html, $raw);
            }

            return $mdImport ? '<div class="post-md-import">' . $html . '</div>' : $html;
        } finally {
            $this->composerPreview = $previousPreview;
        }
    }

    /**
     * @return list<array{type: string, content: string, lang?: string, author?: string}>
     */
    private function splitBlocks(string $raw): array
    {
        $blocks = [];
        $remaining = $raw;

        while ($remaining !== '') {
            $next = $this->findNextSpecialBlock($remaining);
            if ($next === null) {
                $blocks = array_merge($blocks, $this->splitTextBlocks($remaining));
                break;
            }

            if ($next['before'] !== '') {
                $blocks = array_merge($blocks, $this->splitTextBlocks($next['before']));
            }

            $blocks[] = $next['block'];
            $remaining = $next['after'];
        }

        return $blocks !== [] ? $blocks : [['type' => 'text', 'content' => $raw]];
    }

    /**
     * @return array{before: string, block: array{type: string, content: string, lang?: string, author?: string}, after: string}|null
     */
    private function findNextSpecialBlock(string $raw): ?array
    {
        $candidates = [];

        if (preg_match('/[ \t]*```([^\n]*)\n(.*?)\n[ \t]*```/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $lang = trim($m[1][0]);
            $candidates[] = [
                'pos' => $m[0][1],
                'len' => strlen($m[0][0]),
                'block' => ['type' => 'code', 'lang' => strtolower($lang), 'content' => $m[2][0]],
            ];
        }

        if (preg_match('/\[code(?:=([^\]]+))?\](.*?)\[\/code\]/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $candidates[] = [
                'pos' => $m[0][1],
                'len' => strlen($m[0][0]),
                'block' => ['type' => 'code', 'lang' => strtolower($m[1][0] ?? ''), 'content' => $m[2][0]],
            ];
        }

        if (preg_match('/\[quote(?:="([^"]*)"| author="([^"]*)")?\](.*?)\[\/quote\]/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $candidates[] = [
                'pos' => $m[0][1],
                'len' => strlen($m[0][0]),
                'block' => [
                    'type' => 'quote',
                    'author' => $m[1][0] !== '' ? $m[1][0] : ($m[2][0] ?? ''),
                    'content' => $m[3][0],
                ],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $pick = $candidates[0];

        return [
            'before' => substr($raw, 0, $pick['pos']),
            'block' => $pick['block'],
            'after' => substr($raw, $pick['pos'] + $pick['len']),
        ];
    }

    /**
     * @return list<array{type: string, content: string, author?: string}>
     */
    private function splitTextBlocks(string $text): array
    {
        $blocks = [];
        $lines = explode("\n", $text);
        $buffer = [];
        $quoteBuffer = [];
        $quoteAuthor = null;

        $flushText = function () use (&$buffer, &$blocks): void {
            if ($buffer === []) {
                return;
            }
            $blocks[] = ['type' => 'text', 'content' => implode("\n", $buffer)];
            $buffer = [];
        };

        $flushQuote = function () use (&$quoteBuffer, &$quoteAuthor, &$blocks): void {
            if ($quoteBuffer === []) {
                return;
            }
            $blocks[] = [
                'type' => 'quote',
                'author' => $quoteAuthor ?? '',
                'content' => implode("\n", $quoteBuffer),
            ];
            $quoteBuffer = [];
            $quoteAuthor = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^\[quote(?:="([^"]*)"| author="([^"]*)")?\](.*)$/i', $line, $m)) {
                $flushText();
                $quoteAuthor = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : null);
                $rest = $m[3];
                if (preg_match('/^(.*)\[\/quote\]$/i', $rest, $end)) {
                    $blocks[] = [
                        'type' => 'quote',
                        'author' => $quoteAuthor ?? '',
                        'content' => $end[1],
                    ];
                    $quoteAuthor = null;
                } else {
                    $quoteBuffer = [$rest];
                }
                continue;
            }

            if ($quoteBuffer !== [] && $quoteAuthor !== null) {
                if (preg_match('/^(.*)\[\/quote\]$/i', $line, $end)) {
                    if ($end[1] !== '') {
                        $quoteBuffer[] = $end[1];
                    }
                    $flushQuote();
                } else {
                    $quoteBuffer[] = $line;
                }
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushText();
                $quoteBuffer[] = $m[1];
                continue;
            }

            if ($quoteBuffer !== []) {
                $flushQuote();
            }

            $buffer[] = $line;
        }

        if ($quoteBuffer !== []) {
            $flushQuote();
        }
        $flushText();

        return $blocks !== [] ? $blocks : [['type' => 'text', 'content' => $text]];
    }

    private function formatTextBlock(string $text): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];
        $out = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (preg_match('/^\[spoiler(?:="([^"]*)")?\](.*)\[\/spoiler\]$/s', $paragraph, $m)) {
                $out[] = $this->renderSpoiler($m[1] ?? '', $m[2], false);
                continue;
            }

            $standaloneUrl = $this->parseStandaloneUrlParagraph($paragraph);
            if ($standaloneUrl !== null) {
                $out[] = '<p>' . $this->safeLink($standaloneUrl['url'], $standaloneUrl['label'], true) . '</p>';
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/s', $paragraph, $m) && !str_contains($paragraph, "\n")) {
                $level = strlen($m[1]);
                $tag = match ($level) {
                    1 => 'h2',
                    2 => 'h3',
                    default => 'h4',
                };
                $out[] = '<' . $tag . ' class="post-heading">' . $this->formatInline($m[2]) . '</' . $tag . '>';
                continue;
            }

            if ($this->isMarkdownTableParagraph($paragraph)) {
                $out[] = $this->renderMarkdownTable($paragraph);
                continue;
            }

            if (preg_match('/^(- .+(?:\n- .+)*)/', $paragraph, $m)) {
                $items = preg_split('/\n/', $m[1]) ?: [];
                $lis = [];
                foreach ($items as $item) {
                    $item = preg_replace('/^- /', '', $item) ?? $item;
                    $lis[] = '<li>' . $this->formatInline($item) . '</li>';
                }
                $out[] = '<ul>' . implode('', $lis) . '</ul>';
                continue;
            }

            $out[] = '<p>' . nl2br($this->formatInline($paragraph), false) . '</p>';
        }

        return implode("\n", $out);
    }

    private function plainInline(string $text): string
    {
        $text = preg_replace('/(?<!!)\[([^\]]+)\]\(([^\)]+)\)/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/\[url=(https?:\/\/[^\]]+)\]([^\[]+)\[\/url\]/i', '$2 $1', $text) ?? $text;
        $text = preg_replace('/\[url\](https?:\/\/[^\[]+)\[\/url\]/i', '$1', $text) ?? $text;
        $text = preg_replace('/\[(https?:\/\/[^\]]+)\]/i', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '$1', $text) ?? $text;
        $text = preg_replace('/\[spoiler(?:="[^"]*")?\](.*?)\[\/spoiler\]/s', '$1', $text) ?? $text;

        foreach (self::SMILEYS as $code => $emoji) {
            $text = str_replace($code, $emoji, $text);
        }

        return $text;
    }

    private function formatInline(string $text): string
    {
        // Escape first, then apply link/mention/smiley callbacks on escaped text (see MentionParser::linkifyEscaped).
        /** @var array<string, string> */
        $spoilers = [];
        /** @var array<string, string> */
        $images = [];
        /** @var array<string, string> */
        $markdownLinks = [];
        $text = preg_replace_callback(
            '/\[spoiler(?:="([^"]*)")?\](.*?)\[\/spoiler\]/s',
            function (array $m) use (&$spoilers): string {
                $key = '%%SPOILER' . count($spoilers) . '%%';
                $content = $m[2];
                $inline = !str_contains($content, "\n");
                $spoilers[$key] = $this->renderSpoiler($m[1] ?? '', $content, $inline);

                return $key;
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/[^\)]+)\)/i',
            function (array $m) use (&$images): string {
                $key = '%%IMAGE' . count($images) . '%%';
                $images[$key] = $this->renderImage($m[1], $m[2]);

                return $key;
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\(([^\)]+)\)/',
            function (array $m) use (&$markdownLinks): string {
                $key = '%%MDLINK' . count($markdownLinks) . '%%';
                $markdownLinks[$key] = $this->safeLink($m[2], $m[1]);

                return $key;
            },
            $text,
        ) ?? $text;

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach ($images as $key => $html) {
            $text = str_replace($key, $html, $text);
        }

        foreach ($markdownLinks as $key => $html) {
            $text = str_replace($key, $html, $text);
        }

        $text = preg_replace_callback(
            '/\[url=(https?:\/\/[^\]]+)\]([^\[]+)\[\/url\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[2]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[url\](https?:\/\/[^\[]+)\[\/url\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[1]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[(https?:\/\/[^\]]+)\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[1]),
            $text,
        ) ?? $text;

        $text = preg_replace('/`([^`]+)`/', '<code class="inline-code">$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;

        foreach (self::SMILEYS as $code => $emoji) {
            $text = str_replace($code, '<span class="smiley" aria-label="' . $code . '">' . $emoji . '</span>', $text);
        }

        $text = $this->mentions->linkifyEscaped($text);

        foreach ($spoilers as $key => $html) {
            $text = str_replace($key, $html, $text);
        }

        return $text;
    }

    private function renderImage(string $alt, string $url): string
    {
        if (!preg_match('/^https?:\/\//i', $url)) {
            return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || $this->imageHostAllowed === null || !($this->imageHostAllowed)($host)) {
            return '<span class="blocked-image muted">[image blocked]</span>';
        }

        $safeAlt = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->composerPreview) {
            $label = $safeAlt !== '' && $safeAlt !== 'image'
                ? '[image: ' . $safeAlt . ']'
                : '[image]';

            return '<span class="composer-preview-placeholder muted">' . $label . '</span>';
        }

        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" class="post-image" loading="lazy" decoding="async">';
    }

    private function safeLink(string $url, string $label, bool $standalone = false): string
    {
        $url = trim(htmlspecialchars_decode($url, ENT_QUOTES));
        $label = htmlspecialchars_decode($label, ENT_QUOTES);
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (preg_match('/^https?:\/\//i', $url)) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<a href="' . $safeUrl . '" rel="nofollow ugc" target="_blank">' . $safeLabel . '</a>';

            if ($this->linkFormatter !== null && !$this->composerPreview) {
                $html = ($this->linkFormatter)($html, $url, $label, $standalone);
            }

            return $html;
        }

        if ($this->isSafeRelativeUrl($url)) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<a href="' . $safeUrl . '">' . $safeLabel . '</a>';

            if ($this->linkFormatter !== null && !$this->composerPreview) {
                $html = ($this->linkFormatter)($html, $url, $label, $standalone);
            }

            return $html;
        }

        return $safeLabel;
    }

    /**
     * @return array{url: string, label: string}|null
     */
    private function parseStandaloneUrlParagraph(string $paragraph): ?array
    {
        $paragraph = trim($paragraph);
        if ($paragraph === '' || str_contains($paragraph, "\n")) {
            return null;
        }

        if (preg_match('/^https:\/\/\S+$/i', $paragraph) === 1) {
            return ['url' => $paragraph, 'label' => $paragraph];
        }

        if (preg_match('/^\[(https?:\/\/[^\]]+)\]$/i', $paragraph, $m) === 1) {
            return ['url' => $m[1], 'label' => $m[1]];
        }

        if (preg_match('/^\[url\](https?:\/\/[^\[]+)\[\/url\]$/i', $paragraph, $m) === 1) {
            return ['url' => $m[1], 'label' => $m[1]];
        }

        if (preg_match('/^\[url=(https?:\/\/[^\]]+)\]([^\[]+)\[\/url\]$/i', $paragraph, $m) === 1) {
            return ['url' => $m[1], 'label' => $m[2]];
        }

        if (preg_match('/^\[([^\]]+)\]\((https?:\/\/[^\)]+)\)$/', $paragraph, $m) === 1) {
            return ['url' => $m[2], 'label' => $m[1]];
        }

        return null;
    }

    private function isSafeRelativeUrl(string $url): bool
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return false;
        }

        return preg_match('~^/[a-zA-Z0-9/_\-.%?=&+]*$~', $url) === 1;
    }

    private function isMarkdownTableParagraph(string $paragraph): bool
    {
        $lines = preg_split('/\n/', $paragraph) ?: [];
        if (count($lines) < 2) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                return false;
            }
            if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function parseMarkdownTableRow(string $line): array
    {
        $line = trim($line, " \t|");
        if ($line === '') {
            return [];
        }

        return array_map('trim', explode('|', $line));
    }

    private function isMarkdownTableSeparatorRow(string $line): bool
    {
        return (bool) preg_match('/^\|[\s:|-]+\|$/', trim($line));
    }

    private function renderMarkdownTable(string $paragraph): string
    {
        $lines = preg_split('/\n/', trim($paragraph)) ?: [];
        $header = $this->parseMarkdownTableRow($lines[0]);
        $bodyRows = [];

        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if ($this->isMarkdownTableSeparatorRow($line)) {
                continue;
            }
            $bodyRows[] = $this->parseMarkdownTableRow($line);
        }

        $html = '<div class="post-table-wrap"><table class="post-table"><thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . $this->formatInline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($bodyRows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $this->formatInline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function renderCodeBlock(string $lang, string $content): string
    {
        $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLang = preg_replace('/[^a-z0-9_-]+/i', '-', $lang) ?? '';
        $safeLang = trim($safeLang, '-');
        $langAttr = $safeLang !== '' ? ' class="language-' . htmlspecialchars($safeLang, ENT_QUOTES, 'UTF-8') . '"' : '';
        $dataLang = $safeLang !== '' ? ' data-lang="' . htmlspecialchars($safeLang, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<pre class="code-block"' . $dataLang . '><code' . $langAttr . '>' . $escaped . '</code></pre>';
    }

    private function renderQuote(string $author, string $content): string
    {
        $inner = $this->formatTextBlock(trim($content));
        $cite = '';
        if (trim($author) !== '') {
            $cite = '<cite class="quote-author">' . htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</cite>';
        }

        return '<blockquote class="post-quote">' . $cite . $inner . '</blockquote>';
    }

    private function renderSpoiler(string $label, string $content, bool $inline = false): string
    {
        $summary = trim($label) !== '' ? $label : 'Spoiler';
        $safeSummary = htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = $inline
            ? $this->formatInlineWithoutSpoiler(trim($content))
            : $this->formatTextBlock(trim($content));
        $class = 'post-spoiler' . ($inline ? ' post-spoiler-inline' : '');

        return '<details class="' . $class . '"><summary>' . $safeSummary . '</summary><div class="spoiler-content">' . $inner . '</div></details>';
    }

    private function formatInlineWithoutSpoiler(string $text): string
    {
        /** @var array<string, string> */
        $markdownLinks = [];
        $text = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\(([^\)]+)\)/',
            function (array $m) use (&$markdownLinks): string {
                $key = '%%MDLINK' . count($markdownLinks) . '%%';
                $markdownLinks[$key] = $this->safeLink($m[2], $m[1]);

                return $key;
            },
            $text,
        ) ?? $text;

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach ($markdownLinks as $key => $html) {
            $text = str_replace($key, $html, $text);
        }

        $text = preg_replace_callback(
            '/\[url=(https?:\/\/[^\]]+)\]([^\[]+)\[\/url\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[2]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[url\](https?:\/\/[^\[]+)\[\/url\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[1]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/\[(https?:\/\/[^\]]+)\]/i',
            fn (array $m): string => $this->safeLink($m[1], $m[1]),
            $text,
        ) ?? $text;

        $text = preg_replace('/`([^`]+)`/', '<code class="inline-code">$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;

        foreach (self::SMILEYS as $code => $emoji) {
            $text = str_replace($code, '<span class="smiley" aria-label="' . $code . '">' . $emoji . '</span>', $text);
        }

        return $this->mentions->linkifyEscaped($text);
    }
}