<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use RuntimeException;

/**
 * Read and write storage/plugins/{slug}/settings.json merged with manifest defaults.
 */
final class PluginSettingsStore
{
    public function __construct(
        private readonly string $pluginStorageDir,
        private readonly PluginSettingsSchema $schema,
    ) {
    }

    public static function forPlugin(PluginManifest $manifest, string $storageRoot): self
    {
        $dir = rtrim($storageRoot, '/') . '/plugins/' . $manifest->slug;

        return new self($dir, $manifest->settingsSchema);
    }

    public function settingsPath(): string
    {
        return $this->pluginStorageDir . '/settings.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = $this->schema->defaults();
        $path = $this->settingsPath();
        if (!is_file($path)) {
            return $values;
        }

        $raw = file_get_contents($path);
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($decoded)) {
            return $values;
        }

        foreach ($this->schema->settingsFields as $field) {
            if (!$field->isWritable() || !array_key_exists($field->key, $decoded)) {
                continue;
            }

            $values[$field->key] = $decoded[$field->key];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $payload = [];
        foreach ($this->schema->settingsFields as $field) {
            if (!$field->isWritable() || !array_key_exists($field->key, $values)) {
                continue;
            }

            $payload[$field->key] = $values[$field->key];
        }

        if (!is_dir($this->pluginStorageDir) && !mkdir($this->pluginStorageDir, 0775, true) && !is_dir($this->pluginStorageDir)) {
            throw new RuntimeException('Could not create plugin storage directory.');
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->settingsPath(), $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write plugin settings.');
        }
    }
}