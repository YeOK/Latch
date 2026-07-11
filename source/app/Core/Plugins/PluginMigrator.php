<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Database;
use RuntimeException;

/**
 * Applies SQL migration files from plugins/{slug}/migrations/ into plugin.sqlite.
 */
final class PluginMigrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath,
    ) {
    }

    public function migrate(): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS plugin_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS plugin_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );

        if (!is_dir($this->migrationsPath)) {
            return 0;
        }

        $applied = 0;
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);
        $latest = null;

        foreach ($files as $file) {
            $version = basename($file);
            if ($this->isApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Empty plugin migration: ' . $version);
            }

            $pdo->exec($sql);

            $stmt = $pdo->prepare(
                'INSERT INTO plugin_migrations (version, applied_at) VALUES (:version, :applied_at)'
            );
            $stmt->execute([
                'version' => $version,
                'applied_at' => gmdate('c'),
            ]);

            $applied++;
            $latest = $version;
        }

        if ($latest !== null) {
            $this->upsertMeta('schema_version', $latest);
            $this->upsertMeta('applied_at', gmdate('c'));
        }

        return $applied;
    }

    public function pendingCount(): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS plugin_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        if (!is_dir($this->migrationsPath)) {
            return 0;
        }

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
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM plugin_migrations WHERE version = :version');
        $stmt->execute(['version' => $version]);

        return (bool) $stmt->fetchColumn();
    }

    private function upsertMeta(string $key, string $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO plugin_meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
        ]);
    }
}