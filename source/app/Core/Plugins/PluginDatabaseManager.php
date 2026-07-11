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
 * Opens storage/plugins/{slug}/plugin.sqlite and runs plugin migrations on enable/boot.
 */
final class PluginDatabaseManager
{
    /** @var array<string, PluginDatabase> */
    private array $connections = [];

    /**
     * @param array<string, mixed> $sqliteOptions
     */
    public function __construct(
        private readonly string $storageRoot,
        private readonly array $sqliteOptions = [],
    ) {
    }

    public function usesDatabase(PluginManifest $manifest): bool
    {
        return $manifest->databaseEnabled;
    }

    public function storageDir(string $slug): string
    {
        return rtrim($this->storageRoot, '/') . '/plugins/' . $slug;
    }

    public function databasePath(string $slug): string
    {
        return $this->storageDir($slug) . '/plugin.sqlite';
    }

    public function migrationsPath(PluginManifest $manifest): string
    {
        return rtrim($manifest->pluginDir, '/') . '/migrations';
    }

    /**
     * Apply pending migrations. No-op when manifest database is disabled.
     */
    public function migrate(PluginManifest $manifest): int
    {
        if (!$this->usesDatabase($manifest)) {
            return 0;
        }

        $storageDir = $this->storageDir($manifest->slug);
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Could not create plugin storage directory: ' . $storageDir);
        }

        PluginStoragePermissions::ensureWritable($storageDir);

        $db = new Database($this->databasePath($manifest->slug), false, $this->sqliteOptions);
        $migrator = new PluginMigrator($db, $this->migrationsPath($manifest));

        return $migrator->migrate();
    }

    public function pendingCount(PluginManifest $manifest): int
    {
        if (!$this->usesDatabase($manifest)) {
            return 0;
        }

        $path = $this->databasePath($manifest->slug);
        if (!is_file($path)) {
            return (new PluginMigrator(
                new Database($path, false, $this->sqliteOptions),
                $this->migrationsPath($manifest),
            ))->pendingCount();
        }

        return (new PluginMigrator(
            new Database($path, false, $this->sqliteOptions),
            $this->migrationsPath($manifest),
        ))->pendingCount();
    }

    public function open(PluginManifest $manifest): ?PluginDatabase
    {
        if (!$this->usesDatabase($manifest)) {
            return null;
        }

        $slug = $manifest->slug;
        if (isset($this->connections[$slug])) {
            return $this->connections[$slug];
        }

        $path = $this->databasePath($slug);
        if (!is_file($path)) {
            return null;
        }

        $connection = new PluginDatabase(
            new Database($path, false, $this->sqliteOptions),
            $slug,
            $path,
        );
        $this->connections[$slug] = $connection;

        return $connection;
    }
}