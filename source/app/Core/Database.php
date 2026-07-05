<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO SQLite connection with WAL mode for concurrent reads.
 */
final class Database
{
    /** @var array{busy_timeout_ms: int, cache_size_kib: int, mmap_size: int} */
    private const DEFAULT_SQLITE = [
        'busy_timeout_ms' => 5000,
        'cache_size_kib' => 8192,
        'mmap_size' => 0,
    ];

    private PDO $pdo;

    /**
     * @param array<string, mixed> $sqlite Options from config database.sqlite (see default.php).
     */
    public function __construct(string $path, bool $readOnly = false, array $sqlite = [])
    {
        if (!$readOnly) {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create database directory: ' . $dir);
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($readOnly) {
            $options[self::sqliteOpenReadonlyFlag()] = true;
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        try {
            $this->applySqlitePragmas(self::normalizeSqliteOptions($sqlite));
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            if (!$readOnly) {
                $this->pdo->exec('PRAGMA journal_mode = WAL');
            }
        } catch (PDOException $e) {
            if (self::isReadonlyError($e)) {
                throw new RuntimeException(
                    'Database is read-only for the current OS user (SQLite cannot enable WAL).',
                    0,
                    $e,
                );
            }

            throw new RuntimeException('Database pragma failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function fromConfig(Config $config, bool $readOnly = false): self
    {
        return new self(
            (string) $config->get('database.path'),
            $readOnly,
            self::sqliteOptionsFromConfig($config),
        );
    }

    /**
     * Read-only connection for CLI checks that must not write WAL sidecars.
     *
     * @param array<string, mixed> $sqlite
     */
    public static function openReadOnly(string $path, array $sqlite = []): self
    {
        return new self($path, true, $sqlite);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sqliteOptionsFromConfig(Config $config): array
    {
        $sqlite = $config->get('database.sqlite', []);

        return is_array($sqlite) ? $sqlite : [];
    }

    /**
     * @param array<string, mixed> $sqlite
     * @return array{busy_timeout_ms: int, cache_size_kib: int, mmap_size: int}
     */
    public static function normalizeSqliteOptions(array $sqlite): array
    {
        $busy = (int) ($sqlite['busy_timeout_ms'] ?? self::DEFAULT_SQLITE['busy_timeout_ms']);
        $cache = (int) ($sqlite['cache_size_kib'] ?? self::DEFAULT_SQLITE['cache_size_kib']);
        $mmap = (int) ($sqlite['mmap_size'] ?? self::DEFAULT_SQLITE['mmap_size']);

        return [
            'busy_timeout_ms' => max(0, min($busy, 3_600_000)),
            'cache_size_kib' => max(0, min($cache, 1_048_576)),
            'mmap_size' => max(0, $mmap),
        ];
    }

    /**
     * @param array{busy_timeout_ms: int, cache_size_kib: int, mmap_size: int} $sqlite
     */
    private function applySqlitePragmas(array $sqlite): void
    {
        $this->pdo->exec('PRAGMA busy_timeout = ' . $sqlite['busy_timeout_ms']);

        if ($sqlite['cache_size_kib'] > 0) {
            $this->pdo->exec('PRAGMA cache_size = ' . (-$sqlite['cache_size_kib']));
        }

        $this->pdo->exec('PRAGMA mmap_size = ' . $sqlite['mmap_size']);
    }

    private static function sqliteOpenReadonlyFlag(): int
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            return \Pdo\Sqlite::OPEN_READONLY;
        }

        return PDO::SQLITE_OPEN_READONLY;
    }

    private static function isReadonlyError(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'readonly')
            || str_contains($message, 'read-only')
            || str_contains($message, 'attempt to write a readonly database');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}