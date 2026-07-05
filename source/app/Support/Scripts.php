<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use RuntimeException;

/**
 * Paths to repo-root operator scripts (sibling of source/).
 */
final class Scripts
{
    public static function repoRoot(): string
    {
        return dirname(LATCH_ROOT);
    }

    public static function sqliteBackupPath(): string
    {
        return self::repoRoot() . '/scripts/sqlite-backup.php';
    }

    public static function assertExists(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Required script missing: ' . $path);
        }
    }

    public static function runSqliteBackup(string $source, string $dest): void
    {
        $script = self::sqliteBackupPath();
        self::assertExists($script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($source) . ' ' . escapeshellarg($dest) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(
                'sqlite-backup failed' . ($output !== [] ? ': ' . implode("\n", $output) : ''),
            );
        }
    }
}