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

    private const MAX_UPLOAD_BYTES = 2_097_152;

    /**
     * @return array{
     *     title: string,
     *     body: string,
     *     tags: list<string>,
     *     front_matter_board_slug: string|null
     * }
     */
    public function parse(string $raw, string $filename, ?string $titleOverride, bool $stripLeadingH1): array
    {
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
}