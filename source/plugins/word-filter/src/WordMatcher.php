<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\WordFilter;

/**
 * Multi-pattern scanner (Aho-Corasick) with word-boundary checks.
 */
final class WordMatcher
{
    /** @var array<int, array<string, int>> */
    private array $goto = [];

    /** @var array<int, int> */
    private array $fail = [];

    /** @var array<int, list<string>> */
    private array $output = [];

    private int $stateCount = 1;

    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly bool $caseSensitive,
        /** @var list<string> */
        private array $patterns,
    ) {
        $this->patterns = $this->preparePatterns($patterns);
        $this->buildAutomaton();
    }

    /**
     * @return list<array{start: int, end: int, word: string}>
     */
    public function findAll(string $text): array
    {
        if ($this->patterns === [] || $text === '') {
            return [];
        }

        $length = mb_strlen($text, 'UTF-8');
        $state = 0;
        $matches = [];

        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1, 'UTF-8');
            $key = $this->normalizeChar($char);

            while ($state !== 0 && !isset($this->goto[$state][$key])) {
                $state = $this->fail[$state] ?? 0;
            }

            if (isset($this->goto[$state][$key])) {
                $state = $this->goto[$state][$key];
            }

            if (!isset($this->output[$state])) {
                continue;
            }

            foreach ($this->output[$state] as $pattern) {
                $patternLength = mb_strlen($pattern, 'UTF-8');
                $start = $index - $patternLength + 1;
                $end = $index + 1;
                if ($start < 0 || !$this->hasWordBoundaries($text, $start, $end)) {
                    continue;
                }

                $matches[] = [
                    'start' => $start,
                    'end' => $end,
                    'word' => $pattern,
                ];
            }
        }

        if ($matches === []) {
            return [];
        }

        usort(
            $matches,
            static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $b['end'] <=> $a['end'],
        );

        return $this->dedupeOverlapping($matches);
    }

    public function contains(string $text): bool
    {
        return $this->findAll($text) !== [];
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private function preparePatterns(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $normalized = $this->normalizer->normalizeWord($pattern, $this->caseSensitive);
            if ($normalized === '' || isset($unique[$normalized])) {
                continue;
            }

            $unique[$normalized] = true;
        }

        $list = array_keys($unique);
        usort($list, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        return $list;
    }

    private function buildAutomaton(): void
    {
        $this->goto = [0 => []];
        $this->fail = [0 => 0];
        $this->output = [];
        $this->stateCount = 1;

        foreach ($this->patterns as $pattern) {
            $state = 0;
            $length = mb_strlen($pattern, 'UTF-8');
            for ($index = 0; $index < $length; $index++) {
                $char = mb_substr($pattern, $index, 1, 'UTF-8');
                $key = $this->normalizeChar($char);
                if (!isset($this->goto[$state][$key])) {
                    $next = $this->stateCount;
                    $this->stateCount++;
                    $this->goto[$state][$key] = $next;
                    $this->goto[$next] = [];
                    $this->fail[$next] = 0;
                }

                $state = $this->goto[$state][$key];
            }

            $this->output[$state] ??= [];
            if (!in_array($pattern, $this->output[$state], true)) {
                $this->output[$state][] = $pattern;
            }
        }

        $queue = [];
        foreach ($this->goto[0] as $next) {
            $this->fail[$next] = 0;
            $queue[] = $next;
        }

        while ($queue !== []) {
            $state = array_shift($queue);
            foreach ($this->goto[$state] as $char => $next) {
                $queue[] = $next;
                $failState = $this->fail[$state];
                while ($failState !== 0 && !isset($this->goto[$failState][$char])) {
                    $failState = $this->fail[$failState];
                }

                $this->fail[$next] = $this->goto[$failState][$char] ?? 0;
                if (isset($this->output[$this->fail[$next]])) {
                    $this->output[$next] = array_values(array_unique(array_merge(
                        $this->output[$next] ?? [],
                        $this->output[$this->fail[$next]],
                    )));
                }
            }
        }
    }

    private function normalizeChar(string $char): string
    {
        return $this->normalizer->normalizeChar($char, $this->caseSensitive);
    }

    private function hasWordBoundaries(string $text, int $start, int $end): bool
    {
        $before = $start > 0 ? mb_substr($text, $start - 1, 1, 'UTF-8') : '';
        $after = mb_substr($text, $end, 1, 'UTF-8');

        return !$this->normalizer->isWordChar($before) && !$this->normalizer->isWordChar($after);
    }

    /**
     * @param list<array{start: int, end: int, word: string}> $matches
     * @return list<array{start: int, end: int, word: string}>
     */
    private function dedupeOverlapping(array $matches): array
    {
        $kept = [];
        $lastEnd = -1;
        foreach ($matches as $match) {
            if ($match['start'] < $lastEnd) {
                continue;
            }

            $kept[] = $match;
            $lastEnd = $match['end'];
        }

        return $kept;
    }
}