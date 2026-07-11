<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Copy or extract plugins into plugins/{slug}/ (CLI install/remove).
 */
final class PluginInstaller
{
    public function __construct(
        private readonly string $pluginsPath,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Install from a local directory or .zip archive.
     */
    public function installFromSource(string $source): PluginManifest
    {
        $source = trim($source);
        if ($source === '') {
            throw new InvalidArgumentException('Install source path is required');
        }

        if (preg_match('#^https?://#i', $source)) {
            throw new InvalidArgumentException('Remote URLs are not supported in v1 — use a local directory or .zip file');
        }

        $resolved = $this->resolveSourcePath($source);
        if (!is_file($resolved) && !is_dir($resolved)) {
            throw new InvalidArgumentException("Install source not found: {$source}");
        }

        $tempDir = null;
        try {
            if (is_file($resolved) && str_ends_with(strtolower($resolved), '.zip')) {
                $tempDir = $this->extractZipToTemp($resolved);
                $pluginDir = $this->resolvePluginRoot($tempDir);
            } elseif (is_dir($resolved)) {
                $pluginDir = $this->resolvePluginRoot($resolved);
            } else {
                throw new InvalidArgumentException('Install source must be a directory or .zip file');
            }

            $manifest = PluginManifest::fromDirectory($pluginDir);
            if ($manifest->ignored) {
                throw new InvalidArgumentException("Plugin {$manifest->slug} is marked ignored — remove ignored flag before install");
            }

            $targetDir = $this->targetDirectory($manifest->slug);
            if (is_dir($targetDir)) {
                throw new RuntimeException("Plugin already installed: {$manifest->slug} ({$targetDir})");
            }

            if (!is_dir($this->pluginsPath) && !mkdir($this->pluginsPath, 0775, true) && !is_dir($this->pluginsPath)) {
                throw new RuntimeException('Could not create plugins directory: ' . $this->pluginsPath);
            }

            $this->copyDirectory($pluginDir, $targetDir);

            return PluginManifest::fromDirectory($targetDir);
        } catch (\Throwable $e) {
            if (isset($targetDir) && is_dir($targetDir)) {
                $this->deleteDirectory($targetDir);
            }

            throw $e;
        } finally {
            if ($tempDir !== null) {
                $this->deleteDirectory($tempDir);
            }
        }
    }

    public function removeInstalled(string $slug, bool $purgeStorage = false): void
    {
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new InvalidArgumentException('Invalid plugin slug');
        }

        $targetDir = $this->targetDirectory($slug);
        if (!is_dir($targetDir)) {
            throw new InvalidArgumentException("Plugin not installed: {$slug}");
        }

        $this->deleteDirectory($targetDir);

        if ($purgeStorage) {
            $storageDir = rtrim($this->storagePath, '/') . '/plugins/' . $slug;
            if (is_dir($storageDir)) {
                $this->deleteDirectory($storageDir);
            }
        }
    }

    public function targetDirectory(string $slug): string
    {
        return rtrim($this->pluginsPath, '/') . '/' . $slug;
    }

    private function resolveSourcePath(string $source): string
    {
        if (str_starts_with($source, '/')) {
            return $source;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return $source;
        }

        return rtrim($cwd, '/') . '/' . ltrim($source, '/');
    }

    private function resolvePluginRoot(string $dir): string
    {
        $dir = realpath($dir) ?: $dir;
        if (!is_dir($dir)) {
            throw new InvalidArgumentException('Plugin source is not a directory');
        }

        if (is_file($dir . '/plugin.json')) {
            return $dir;
        }

        $candidates = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $dir . '/' . $entry;
            if (is_dir($child) && is_file($child . '/plugin.json')) {
                $candidates[] = $child;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates !== []) {
            throw new InvalidArgumentException('Archive contains multiple plugin directories — install one plugin per archive');
        }

        throw new InvalidArgumentException('No plugin.json found in install source');
    }

    private function extractZipToTemp(string $zipPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('php-zip extension required for .zip installs');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException("Could not open zip archive: {$zipPath}");
        }

        $tempDir = sys_get_temp_dir() . '/latch-plugin-install-' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $zip->close();
            throw new RuntimeException('Could not create temporary directory for zip extraction');
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);
            if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
                $zip->close();
                $this->deleteDirectory($tempDir);
                throw new InvalidArgumentException('Zip archive contains unsafe paths');
            }

            $target = $tempDir . '/' . ltrim($normalized, '/');
            if (str_ends_with($normalized, '/')) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    $zip->close();
                    $this->deleteDirectory($tempDir);
                    throw new RuntimeException('Failed to create directory while extracting zip');
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                $zip->close();
                $this->deleteDirectory($tempDir);
                throw new RuntimeException('Failed to create parent directory while extracting zip');
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                $zip->close();
                $this->deleteDirectory($tempDir);
                throw new RuntimeException("Failed to read zip entry: {$name}");
            }

            if (file_put_contents($target, $contents) === false) {
                $zip->close();
                $this->deleteDirectory($tempDir);
                throw new RuntimeException("Failed to write zip entry: {$name}");
            }
        }

        $zip->close();

        return $tempDir;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $source = realpath($source) ?: $source;
        if (!is_dir($source)) {
            throw new InvalidArgumentException('Plugin source directory missing');
        }

        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException('Failed to create plugin directory: ' . $destination);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace($source, '', $item->getPathname()), '/\\');
            if ($relative === '' || $relative === '.git') {
                continue;
            }

            if (str_starts_with($relative, '.git/')) {
                continue;
            }

            $target = $destination . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new RuntimeException('Failed to create directory: ' . $target);
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Failed to create directory: ' . $parent);
            }

            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Failed to copy file: ' . $relative);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}