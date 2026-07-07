<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\MdImport;

/**
 * Parse uploaded markdown into topic title and post body chunk(s).
 */
final class MarkdownImport
{
    public const MARKER = '<!-- latch-md-import -->';

    /** Broken image path on the configured CDN — passes BodyGuard; replace via the editor. */
    public const IMAGE_PLACEHOLDER_PATH = '/.md-import/REPLACE-ME.png';

    private const MAX_UPLOAD_BYTES = 2_097_152;

    /**
     * @return array{
     *     title: string,
     *     body: string,
     *     tags: list<string>,
     *     front_matter_board_slug: string|null
     * }
     */
    public function parse(
        string $raw,
        string $filename,
        ?string $titleOverride,
        bool $stripLeadingH1,
        ?string $imagePlaceholderUrl = null,
    ): array {
        $raw = $this->normalizeNewlines($raw);
        if (strlen($raw) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Markdown file is too large (max 2 MiB).');
        }

        $parsed = $this->parseFrontMatter($raw);
        $body = $parsed['body'];
        $tags = $parsed['tags'];
        $boardSlug = $parsed['board_slug'];

        $title = $this->resolveTitle($body, $filename, $titleOverride ?? $parsed['title']);
        if ($stripLeadingH1) {
            $body = $this->stripLeadingH1($body, $title);
        }

        if ($imagePlaceholderUrl !== null && trim($imagePlaceholderUrl) !== '') {
            $body = $this->normalizeImages($body, trim($imagePlaceholderUrl));
        }

        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Markdown file has no content after the title.');
        }

        return [
            'title' => $title,
            'body' => $body,
            'tags' => $tags,
            'front_matter_board_slug' => $boardSlug,
        ];
    }

    /**
     * @return list<string>
     */
    public function splitIntoPosts(string $body, int $maxPostBytes): array
    {
        $markerPrefix = self::MARKER . "\n";
        $maxContent = max(1, $maxPostBytes - strlen($markerPrefix));

        if (strlen($body) <= $maxContent) {
            return [$markerPrefix . $body];
        }

        $sections = preg_split('/\n(?=## )/', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [$body];
        $chunks = [];
        $current = '';

        foreach ($sections as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }

            if (strlen($section) > $maxContent) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach ($this->hardSplit($section, $maxContent) as $part) {
                    $chunks[] = $part;
                }
                continue;
            }

            $candidate = $current === '' ? $section : $current . "\n\n" . $section;
            if (strlen($candidate) <= $maxContent) {
                $current = $candidate;
                continue;
            }

            $chunks[] = $current;
            $current = $section;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        if ($chunks === []) {
            throw new \InvalidArgumentException('Could not split markdown into post-sized chunks.');
        }

        $posts = [];
        foreach ($chunks as $index => $chunk) {
            $posts[] = ($index === 0 ? $markerPrefix : '') . $chunk;
        }

        return $posts;
    }

    private function normalizeNewlines(string $raw): string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);

        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new \InvalidArgumentException('Markdown file must be UTF-8 text.');
        }

        return $raw;
    }

    /**
     * @return array{title: string|null, board_slug: string|null, tags: list<string>, body: string}
     */
    private function parseFrontMatter(string $raw): array
    {
        if (!preg_match('/\A---\n(.*?)\n---\n+/s', $raw, $match)) {
            return [
                'title' => null,
                'board_slug' => null,
                'tags' => [],
                'body' => $raw,
            ];
        }

        $title = null;
        $boardSlug = null;
        $tags = [];

        foreach (preg_split('/\n/', trim($match[1])) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.+)$/', trim($line), $parts)) {
                continue;
            }

            $key = strtolower($parts[1]);
            $value = trim($parts[2]);
            $value = trim($value, "\"'");

            match ($key) {
                'title' => $title = $value !== '' ? $value : null,
                'board' => $boardSlug = $value !== '' ? $value : null,
                'tags' => $tags = $this->parseTags($value),
                default => null,
            };
        }

        $body = (string) preg_replace('/\A---\n.*?\n---\n+/s', '', $raw, 1);

        return [
            'title' => $title,
            'board_slug' => $boardSlug,
            'tags' => $tags,
            'body' => $body,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseTags(string $value): array
    {
        $tags = [];
        foreach (preg_split('/\s*,\s*/', $value) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function resolveTitle(string $body, string $filename, ?string $override): string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }

        if (preg_match('/^#\s+(.+)$/m', $body, $match)) {
            return trim($match[1]);
        }

        $base = preg_replace('/\.md$/i', '', basename($filename)) ?? basename($filename);
        $base = str_replace(['-', '_'], ' ', $base);
        $base = trim($base);

        if ($base !== '') {
            return ucwords($base);
        }

        throw new \InvalidArgumentException('Could not determine a topic title. Add a title, a # heading, or use a descriptive filename.');
    }

    private function stripLeadingH1(string $body, string $title): string
    {
        if (!preg_match('/^#\s+(.+?)(?:\n+|$)/s', $body, $match, PREG_OFFSET_CAPTURE)) {
            return $body;
        }

        $heading = trim($match[1][0]);
        if (strcasecmp($heading, $title) !== 0) {
            return $body;
        }

        $rest = substr($body, $match[0][1] + strlen($match[0][0]));

        return ltrim($rest, "\n");
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $section, int $maxContent): array
    {
        $parts = [];
        $paragraphs = preg_split("/\n{2,}/", $section) ?: [$section];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (strlen($paragraph) > $maxContent) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                $offset = 0;
                $length = strlen($paragraph);
                while ($offset < $length) {
                    $parts[] = substr($paragraph, $offset, $maxContent);
                    $offset += $maxContent;
                }
                continue;
            }

            $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            if (strlen($candidate) <= $maxContent) {
                $current = $candidate;
                continue;
            }

            $parts[] = $current;
            $current = $paragraph;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts !== [] ? $parts : [$section];
    }

    /**
     * Convert HTML images and disallowed markdown image URLs into placeholder markdown images.
     */
    private function normalizeImages(string $body, string $placeholderUrl): string
    {
        [$masked, $slots] = $this->maskCodeSamples($body);

        $masked = (string) preg_replace_callback(
            '/<picture\b[^>]*>[\s\S]*?<\/picture>/i',
            fn (array $match): string => $this->htmlImageToMarkdown($match[0], $placeholderUrl),
            $masked,
        );

        $masked = (string) preg_replace_callback(
            '/<img\b[^>]*\/?>/i',
            fn (array $match): string => $this->htmlImageToMarkdown($match[0], $placeholderUrl),
            $masked,
        );

        $allowedHost = parse_url($placeholderUrl, PHP_URL_HOST);
        $allowedHost = is_string($allowedHost) ? strtolower($allowedHost) : '';

        $masked = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^\)]+)\)/',
            function (array $match) use ($placeholderUrl, $allowedHost): string {
                $alt = $this->markdownImageLabel($match[1], $match[2]);
                $url = trim($match[2]);
                if ($url === $placeholderUrl) {
                    return $match[0];
                }

                if (preg_match('#^https?://#i', $url)) {
                    $host = parse_url($url, PHP_URL_HOST);
                    $host = is_string($host) ? strtolower($host) : '';
                    if ($host !== '' && $host === $allowedHost) {
                        return $match[0];
                    }
                }

                return '![' . $alt . '](' . $placeholderUrl . ')';
            },
            $masked,
        );

        return $this->unmaskCodeSamples($masked, $slots);
    }

    private function htmlImageToMarkdown(string $html, string $placeholderUrl): string
    {
        $src = $this->htmlTagAttr($html, 'src');
        if ($src === null || trim($src) === '') {
            $srcset = $this->htmlTagAttr($html, 'srcset');
            if ($srcset !== null) {
                $src = trim(explode(',', $srcset)[0] ?? '');
                $src = trim(explode(' ', $src)[0] ?? '');
            }
        }

        $alt = $this->markdownImageLabel($this->htmlTagAttr($html, 'alt'), $src ?? '');

        return '![' . $alt . '](' . $placeholderUrl . ')';
    }

    private function htmlTagAttr(string $tag, string $name): ?string
    {
        $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is';
        if (preg_match($pattern, $tag, $match)) {
            return trim($match[2]);
        }

        $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*([^\s"\'=<>`]+)/i';
        if (preg_match($pattern, $tag, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function markdownImageLabel(?string $alt, ?string $src): string
    {
        $alt = trim((string) $alt);
        if ($alt !== '') {
            return $this->sanitizeMarkdownImageAlt($alt);
        }

        $src = trim((string) $src);
        if ($src !== '') {
            $path = parse_url($src, PHP_URL_PATH);
            $filename = is_string($path) && $path !== '' ? basename($path) : '';
            if ($filename !== '' && $filename !== '/' && $filename !== '.') {
                return $this->sanitizeMarkdownImageAlt($filename);
            }
        }

        return 'Image (replace me)';
    }

    private function sanitizeMarkdownImageAlt(string $alt): string
    {
        $alt = preg_replace('/[\x00-\x1F\x7F]/u', '', $alt) ?? $alt;
        $alt = preg_replace('/\s+/u', ' ', trim($alt)) ?? $alt;
        $alt = str_replace(['[', ']', '\\'], ['(', ')', '/'], $alt);

        if ($alt === '') {
            return 'Image (replace me)';
        }

        if (!str_contains(strtolower($alt), 'replace')) {
            $alt .= ' (replace image)';
        }

        return $alt;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function maskCodeSamples(string $body): array
    {
        $slots = [];
        $body = (string) preg_replace_callback(
            '/```[\s\S]*?```/',
            function (array $match) use (&$slots): string {
                $key = '%%MDIMPORTCODE' . count($slots) . '%%';
                $slots[$key] = $match[0];

                return $key;
            },
            $body,
        );

        $body = (string) preg_replace_callback(
            '/`[^`]*`/',
            function (array $match) use (&$slots): string {
                $key = '%%MDIMPORTCODE' . count($slots) . '%%';
                $slots[$key] = $match[0];

                return $key;
            },
            $body,
        );

        return [$body, $slots];
    }

    /**
     * @param array<string, string> $slots
     */
    private function unmaskCodeSamples(string $body, array $slots): string
    {
        foreach ($slots as $key => $value) {
            $body = str_replace($key, $value, $body);
        }

        return $body;
    }
}