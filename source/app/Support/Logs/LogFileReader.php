<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

use RuntimeException;

final class LogFileReader
{
    public function __construct(
        private readonly int $tailWindowBytes = 2_097_152,
        private readonly int $searchScanBytes = 524_288,
    ) {
    }

    /**
     * @param ?callable(string): bool $lineMatcher
     * @param ?array{size: int, mtime: int} $cursorFingerprint
     * @return array{
     *   lines: list<string>,
     *   next_cursor: ?int,
     *   fingerprint: array{size: int, mtime: int},
     *   rotated: bool,
     *   scan_budget_exhausted: bool,
     *   matches_exhausted: bool,
     *   bytes_scanned: int
     * }
     */
    public function tail(
        string $path,
        int $limit = 200,
        ?int $cursor = null,
        ?array $cursorFingerprint = null,
        ?callable $lineMatcher = null,
    ): array {
        if (!is_file($path)) {
            throw new RuntimeException('Log path is not a regular file: ' . $path);
        }

        if (is_link($path)) {
            $real = realpath($path);
            if ($real === false || !is_file($real)) {
                throw new RuntimeException('Log symlink is not readable: ' . $path);
            }
            $path = $real;
        }

        $stat = stat($path);
        if ($stat === false) {
            throw new RuntimeException('Cannot stat log file: ' . $path);
        }

        $fingerprint = [
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
        ];

        $rotated = false;
        if ($cursorFingerprint !== null && $cursor !== null) {
            if (
                $cursorFingerprint['size'] !== $fingerprint['size']
                || $cursorFingerprint['mtime'] !== $fingerprint['mtime']
            ) {
                $rotated = true;
                $cursor = null;
            }
        }

        $fileSize = $fingerprint['size'];
        if ($fileSize === 0) {
            return $this->emptyResult($fingerprint, $rotated);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open log file: ' . $path);
        }

        try {
            $hasFilter = $lineMatcher !== null;
            $scanBudget = $hasFilter ? $this->searchScanBytes : $this->tailWindowBytes;
            $endOffset = $cursor ?? $fileSize;
            $endOffset = min($endOffset, $fileSize);

            if ($hasFilter) {
                return $this->tailFiltered(
                    $handle,
                    $endOffset,
                    $scanBudget,
                    $limit,
                    $lineMatcher,
                    $fingerprint,
                    $rotated,
                );
            }

            return $this->tailUnfiltered(
                $handle,
                $endOffset,
                $scanBudget,
                $limit,
                $fingerprint,
                $rotated,
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{size: int, mtime: int} $fingerprint
     * @return array{
     *   lines: list<string>,
     *   next_cursor: ?int,
     *   fingerprint: array{size: int, mtime: int},
     *   rotated: bool,
     *   scan_budget_exhausted: bool,
     *   matches_exhausted: bool,
     *   bytes_scanned: int
     * }
     */
    private function tailUnfiltered(
        mixed $handle,
        int $endOffset,
        int $scanBudget,
        int $limit,
        array $fingerprint,
        bool $rotated,
    ): array {
        $minOffset = max(0, $endOffset - $scanBudget);
        $chunk = $this->readRange($handle, $minOffset, $endOffset);
        $lines = $this->linesFromChunk($chunk, $minOffset > 0);
        $lines = $this->trimTrailingEmpty($lines);

        if (count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }

        $nextCursor = $minOffset > 0 ? $minOffset : null;

        return [
            'lines' => array_values($lines),
            'next_cursor' => $nextCursor,
            'fingerprint' => $fingerprint,
            'rotated' => $rotated,
            'scan_budget_exhausted' => false,
            'matches_exhausted' => false,
            'bytes_scanned' => $endOffset - $minOffset,
        ];
    }

    /**
     * @param callable(string): bool $lineMatcher
     * @param array{size: int, mtime: int} $fingerprint
     * @return array{
     *   lines: list<string>,
     *   next_cursor: ?int,
     *   fingerprint: array{size: int, mtime: int},
     *   rotated: bool,
     *   scan_budget_exhausted: bool,
     *   matches_exhausted: bool,
     *   bytes_scanned: int
     * }
     */
    private function tailFiltered(
        mixed $handle,
        int $endOffset,
        int $scanBudget,
        int $limit,
        callable $lineMatcher,
        array $fingerprint,
        bool $rotated,
    ): array {
        $collected = [];
        $pos = $endOffset;
        $bytesScanned = 0;
        $minReached = false;

        while ($pos > 0 && count($collected) < $limit && $bytesScanned < $scanBudget) {
            $windowStart = max(0, $pos - $scanBudget + $bytesScanned);
            $readStart = max(0, $pos - min(65_536, $pos - $windowStart));
            if ($readStart >= $pos) {
                break;
            }

            $chunk = $this->readRange($handle, $readStart, $pos);
            $bytesScanned += $pos - $readStart;
            $pos = $readStart;

            $lines = $this->linesFromChunk($chunk, $readStart > 0);
            $lines = $this->trimTrailingEmpty($lines);
            $lines = array_reverse($lines);

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                if ($lineMatcher($line)) {
                    array_unshift($collected, $line);
                    if (count($collected) >= $limit) {
                        break 2;
                    }
                }
            }

            if ($readStart === 0) {
                $minReached = true;
                break;
            }
        }

        $nextCursor = null;
        if ($pos > 0) {
            $nextCursor = $pos;
        }

        $scanBudgetExhausted = $bytesScanned >= $scanBudget && $nextCursor !== null && $nextCursor > 0;
        $matchesExhausted = !$scanBudgetExhausted && $minReached;

        return [
            'lines' => array_values($collected),
            'next_cursor' => $nextCursor,
            'fingerprint' => $fingerprint,
            'rotated' => $rotated,
            'scan_budget_exhausted' => $scanBudgetExhausted,
            'matches_exhausted' => $matchesExhausted,
            'bytes_scanned' => $bytesScanned,
        ];
    }

    private function readRange(mixed $handle, int $start, int $end): string
    {
        if ($end <= $start) {
            return '';
        }

        fseek($handle, $start);
        $data = fread($handle, $end - $start);

        return is_string($data) ? $data : '';
    }

    /**
     * @return list<string>
     */
    private function linesFromChunk(string $chunk, bool $dropPartialFirstLine): array
    {
        if ($chunk === '') {
            return [];
        }

        $lines = explode("\n", $chunk);
        if ($dropPartialFirstLine && $lines !== []) {
            array_shift($lines);
        }

        return array_map(static fn (string $line): string => rtrim($line, "\r"), $lines);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function trimTrailingEmpty(array $lines): array
    {
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param array{size: int, mtime: int} $fingerprint
     * @return array{
     *   lines: list<string>,
     *   next_cursor: ?int,
     *   fingerprint: array{size: int, mtime: int},
     *   rotated: bool,
     *   scan_budget_exhausted: bool,
     *   matches_exhausted: bool,
     *   bytes_scanned: int
     * }
     */
    private function emptyResult(array $fingerprint, bool $rotated): array
    {
        return [
            'lines' => [],
            'next_cursor' => null,
            'fingerprint' => $fingerprint,
            'rotated' => $rotated,
            'scan_budget_exhausted' => false,
            'matches_exhausted' => false,
            'bytes_scanned' => 0,
        ];
    }
}