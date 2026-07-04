<?php

declare(strict_types=1);

namespace Latch\Support;

use Latch\Core\Config;

/**
 * Installed vs tree/git version for the admin dashboard.
 * Uses file reads and a short-lived cache — no git subprocess on every request.
 */
final class VersionInfo
{
    public const STATUS_CURRENT = 'current';
    public const STATUS_BEHIND = 'behind';
    public const STATUS_AHEAD = 'ahead';
    public const STATUS_UNKNOWN = 'unknown';

    private const GIT_CACHE_TTL = 300;

    /** @var array<string, mixed>|null */
    private static ?array $requestSnapshot = null;

    /**
     * @return array{
     *     installed: string,
     *     tree: string|null,
     *     git: string|null,
     *     status: string,
     *     status_label: string
     * }
     */
    public static function snapshot(Config $config, string $latchRoot): array
    {
        if (self::$requestSnapshot !== null) {
            return self::$requestSnapshot;
        }

        $installed = trim((string) $config->get('app.version', '0.0.0'));
        $repoRoot = dirname($latchRoot);
        $tree = self::readVersionFile($repoRoot);
        $storagePath = (string) $config->get('paths.storage', $latchRoot . '/storage');
        $git = self::gitLabel($repoRoot, $storagePath);
        $status = self::compareStatus($installed, $tree);

        self::$requestSnapshot = [
            'installed' => $installed,
            'tree' => $tree,
            'git' => $git,
            'status' => $status,
            'status_label' => self::statusLabel($status),
        ];

        return self::$requestSnapshot;
    }

    public static function readVersionFile(string $repoRoot): ?string
    {
        foreach ([$repoRoot . '/VERSION', $repoRoot . '/version'] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $version = trim((string) file_get_contents($path));
            if ($version !== '') {
                return ltrim($version, 'vV');
            }
        }

        return null;
    }

    public static function compareStatus(string $installed, ?string $tree): string
    {
        if ($tree === null || $tree === '') {
            return self::STATUS_UNKNOWN;
        }

        $cmp = version_compare($installed, $tree);

        if ($cmp < 0) {
            return self::STATUS_BEHIND;
        }

        if ($cmp > 0) {
            return self::STATUS_AHEAD;
        }

        return self::STATUS_CURRENT;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_BEHIND => 'Update available',
            self::STATUS_AHEAD => 'Ahead of tree VERSION',
            self::STATUS_CURRENT => 'Up to date',
            default => 'Tree version unknown',
        };
    }

    private static function gitLabel(string $repoRoot, string $storagePath): ?string
    {
        if (!is_dir($repoRoot . '/.git')) {
            return null;
        }

        $cacheFile = rtrim($storagePath, '/') . '/cache/version-git.json';
        $cached = self::readGitCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $label = self::runGitDescribe($repoRoot);
        if ($label !== null) {
            self::writeGitCache($cacheFile, $label);
        }

        return $label;
    }

    private static function readGitCache(string $cacheFile): ?string
    {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        if (filemtime($cacheFile) < time() - self::GIT_CACHE_TTL) {
            return null;
        }

        $raw = file_get_contents($cacheFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return null;
        }

        return $label;
    }

    private static function writeGitCache(string $cacheFile, string $label): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 02770, true)) {
            return;
        }

        @file_put_contents($cacheFile, json_encode([
            'label' => $label,
            'cached_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    private static function runGitDescribe(string $repoRoot): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $cmd = ['git', '-C', $repoRoot, 'describe', '--tags', '--always', '--dirty'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                break;
            }
            usleep(20_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);

        $label = trim($stdout);
        if ($label === '') {
            return null;
        }

        return $label;
    }
}