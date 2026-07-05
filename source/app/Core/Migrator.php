<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use RuntimeException;

/**
 * Applies SQL migration files in database/migrations/.
 */
final class Migrator
{
    /** @var array<string, list<string>> New filename => legacy schema_migrations.version keys. */
    private const LEGACY_VERSION_ALIASES = [
        '001a_security.sql' => ['001.5_security.sql'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath,
    ) {
    }

    public function migrate(): int
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = 0;
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $version = basename($file);
            if ($this->isApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Empty migration: ' . $version);
            }

            $this->db->pdo()->exec($sql);

            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
            );
            $stmt->execute([
                'version' => $version,
                'applied_at' => gmdate('c'),
            ]);

            $applied++;
        }

        return $applied;
    }

    public function pendingCount(): int
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $pending = 0;
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!$this->isApplied(basename($file))) {
                $pending++;
            }
        }

        return $pending;
    }

    private function isApplied(string $version): bool
    {
        foreach ($this->appliedVersionKeys($version) as $key) {
            if ($this->hasMigrationRecord($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function appliedVersionKeys(string $version): array
    {
        return array_merge([$version], self::LEGACY_VERSION_ALIASES[$version] ?? []);
    }

    private function hasMigrationRecord(string $version): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $stmt->execute(['version' => $version]);

        return (bool) $stmt->fetchColumn();
    }
}