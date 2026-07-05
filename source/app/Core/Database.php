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
    private PDO $pdo;

    public function __construct(string $path, bool $readOnly = false)
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
            $options[PDO::SQLITE_OPEN_READONLY] = true;
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        try {
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

    /** Read-only connection for CLI checks that must not write WAL sidecars. */
    public static function openReadOnly(string $path): self
    {
        return new self($path, true);
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