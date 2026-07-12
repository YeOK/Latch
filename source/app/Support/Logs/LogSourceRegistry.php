<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

use Latch\Core\Config;

final class LogSourceRegistry
{
    public const DEFAULT_ALLOWED_ROOTS = ['/var/log'];

    /** @var list<string> */
    private array $deniedPrefixes = [];

    /** @var list<string> */
    private array $effectiveRoots = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourcesById = [];

    public function __construct(
        private readonly Config $config,
        private readonly string $latchRoot,
        private readonly string $storagePath,
    ) {
        $this->deniedPrefixes = $this->buildDeniedPrefixes();
        $this->effectiveRoots = $this->buildEffectiveAllowedRoots();
        $this->sourcesById = $this->loadSources();
    }

    public static function fromConfig(Config $config): self
    {
        $storagePath = rtrim((string) $config->get('paths.storage'), '/');
        $latchRoot = dirname($storagePath);

        return new self($config, $latchRoot, $storagePath);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSources(): array
    {
        return array_values($this->sourcesById);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSource(string $id): ?array
    {
        return $this->sourcesById[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function effectiveAllowedRoots(): array
    {
        return $this->effectiveRoots;
    }

    /**
     * @return list<string>
     */
    public function deniedPrefixes(): array
    {
        return $this->deniedPrefixes;
    }

    public function isDeniedPath(string $path): bool
    {
        $normalized = $this->normalizePath($path);

        foreach ($this->deniedPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isUnderAllowedRoot(string $path): bool
    {
        $normalized = $this->normalizePath($path);

        foreach ($this->effectiveRoots as $root) {
            if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function buildDeniedPrefixes(): array
    {
        $storage = rtrim($this->storagePath, '/');
        $root = rtrim($this->latchRoot, '/');

        return [
            '/etc/',
            '/proc/',
            '/sys/',
            '/dev/',
            '/root/',
            '/run/secrets/',
            $root . '/config/',
            $root . '/vendor/',
            $root . '/storage/database/',
            $root . '/storage/backups/',
            $root . '/storage/cache/',
            $root . '/storage/uploads/',
            $root . '/storage/plugins/',
            $storage . '/database/',
            $storage . '/backups/',
            $storage . '/cache/',
            $storage . '/uploads/',
            $storage . '/plugins/',
        ];
    }

    /**
     * @return list<string>
     */
    private function buildEffectiveAllowedRoots(): array
    {
        $roots = self::DEFAULT_ALLOWED_ROOTS;
        $roots[] = rtrim($this->storagePath, '/') . '/logs';

        $max = max(0, (int) $this->config->get('logs.max_allowed_roots', 5));
        $configured = $this->config->get('logs.allowed_roots', []);
        $added = 0;

        if (is_array($configured)) {
            foreach ($configured as $root) {
                if (!is_string($root) || $root === '') {
                    continue;
                }

                if ($added >= $max) {
                    error_log('Latch logs: max_allowed_roots exceeded; skipping ' . $root);
                    break;
                }

                $validated = $this->validateAllowedRoot($root);
                if ($validated === null) {
                    continue;
                }

                $roots[] = $validated;
                $added++;
            }
        }

        $unique = [];
        foreach ($roots as $root) {
            $normalized = rtrim($root, '/');
            if ($normalized !== '' && !in_array($normalized, $unique, true)) {
                $unique[] = $normalized;
            }
        }

        return $unique;
    }

    private function validateAllowedRoot(string $root): ?string
    {
        if (!str_starts_with($root, '/')) {
            error_log('Latch logs: allowed_roots entry must be absolute: ' . $root);

            return null;
        }

        $normalized = rtrim($root, '/');
        $blocked = ['/', '/var', '/home', '/usr', '/root'];
        if (in_array($normalized, $blocked, true)) {
            error_log('Latch logs: rejected overly broad allowed_root: ' . $root);

            return null;
        }

        foreach ($this->deniedPrefixes as $denied) {
            $deniedTrim = rtrim($denied, '/');
            if (str_starts_with($deniedTrim, $normalized) || str_starts_with($normalized, $deniedTrim)) {
                error_log('Latch logs: allowed_root overlaps denylist: ' . $root);

                return null;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSources(): array
    {
        $sources = [];

        foreach ($this->builtInSources() as $source) {
            $sources[$source['id']] = $this->resolveSource($source, true);
        }

        if (!$this->config->get('logs.server_logs_enabled', false)) {
            return $sources;
        }

        $configured = $this->config->get('logs.sources', []);
        if (!is_array($configured)) {
            return $sources;
        }

        foreach ($configured as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $validated = $this->validateConfiguredSource($entry);
            if ($validated === null) {
                continue;
            }

            if (isset($sources[$validated['id']])) {
                error_log('Latch logs: duplicate source id skipped: ' . $validated['id']);
                continue;
            }

            $sources[$validated['id']] = $this->resolveSource($validated, false);
        }

        return $sources;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function builtInSources(): array
    {
        $logsDir = rtrim($this->storagePath, '/') . '/logs';

        return [
            [
                'id' => 'latch.security',
                'label' => 'Security events',
                'group' => 'Latch application',
                'path' => $logsDir . '/security.log',
                'format' => 'json_lines',
            ],
            [
                'id' => 'latch.restore',
                'label' => 'Restore (break-glass)',
                'group' => 'Latch application',
                'path' => $logsDir . '/restore.log',
                'format' => 'text',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private function validateConfiguredSource(array $entry): ?array
    {
        $id = trim((string) ($entry['id'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $id)) {
            error_log('Latch logs: invalid source id: ' . $id);

            return null;
        }

        if (str_starts_with($id, 'latch.')) {
            error_log('Latch logs: reserved source id prefix: ' . $id);

            return null;
        }

        $path = trim((string) ($entry['path'] ?? ''));
        if (!str_starts_with($path, '/')) {
            error_log('Latch logs: source path must be absolute: ' . $path);

            return null;
        }

        $format = trim((string) ($entry['format'] ?? 'text'));
        if (!in_array($format, ['text', 'json_lines'], true)) {
            error_log('Latch logs: invalid format for ' . $id);

            return null;
        }

        return [
            'id' => $id,
            'label' => trim((string) ($entry['label'] ?? $id)),
            'group' => trim((string) ($entry['group'] ?? 'Other')),
            'path' => $path,
            'format' => $format,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function resolveSource(array $source, bool $builtIn): array
    {
        $intended = (string) $source['path'];
        $resolved = is_file($intended) ? realpath($intended) : false;
        $readPath = is_string($resolved) ? $resolved : $intended;

        $status = $this->resolveStatus($readPath, $builtIn);
        $fingerprint = $this->fingerprintFor($readPath);

        return [
            'id' => (string) $source['id'],
            'label' => (string) $source['label'],
            'group' => (string) $source['group'],
            'format' => (string) $source['format'],
            'path' => $readPath,
            'intended_path' => $intended,
            'built_in' => $builtIn,
            'status' => $status,
            'size_bytes' => $fingerprint['size'],
            'mtime' => $fingerprint['mtime'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function resolveStatus(string $path, bool $builtIn): string
    {
        if ($this->isDeniedPath($path)) {
            return 'denied';
        }

        if (!$builtIn && !$this->isUnderAllowedRoot($path)) {
            return 'denied';
        }

        if (!is_file($path)) {
            return 'missing';
        }

        if (!is_readable($path)) {
            return 'permission_denied';
        }

        return 'readable';
    }

    /**
     * @return array{size: int, mtime: int}
     */
    private function fingerprintFor(string $path): array
    {
        if (!is_file($path)) {
            return ['size' => 0, 'mtime' => 0];
        }

        $stat = stat($path);

        return [
            'size' => $stat !== false ? (int) $stat['size'] : 0,
            'mtime' => $stat !== false ? (int) $stat['mtime'] : 0,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/') . '/';
    }
}