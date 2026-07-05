<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Line-based text diff for staff revision review (HTML-safe output).
 */
final class TextDiff
{
    /**
     * @return list<array{kind: string, text: string}>
     */
    public function lines(string $old, string $new): array
    {
        $oldLines = $this->splitLines($old);
        $newLines = $this->splitLines($new);
        $lcs = $this->longestCommonSubsequence($oldLines, $newLines);
        $result = [];
        $oi = 0;
        $ni = 0;

        foreach ($lcs as $line) {
            while ($oi < count($oldLines) && $oldLines[$oi] !== $line) {
                $result[] = ['kind' => 'remove', 'text' => $oldLines[$oi]];
                $oi++;
            }
            while ($ni < count($newLines) && $newLines[$ni] !== $line) {
                $result[] = ['kind' => 'add', 'text' => $newLines[$ni]];
                $ni++;
            }
            $result[] = ['kind' => 'same', 'text' => $line];
            $oi++;
            $ni++;
        }

        while ($oi < count($oldLines)) {
            $result[] = ['kind' => 'remove', 'text' => $oldLines[$oi]];
            $oi++;
        }
        while ($ni < count($newLines)) {
            $result[] = ['kind' => 'add', 'text' => $newLines[$ni]];
            $ni++;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $text);
        if (!is_array($lines)) {
            return [$text];
        }

        return $lines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function longestCommonSubsequence(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        $sequence = [];
        $i = $m;
        $j = $n;
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                array_unshift($sequence, $a[$i - 1]);
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $sequence;
    }
}