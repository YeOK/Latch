<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Serves /assets/… from theme packs without booting Application.
 *
 * Apache Alias cannot do this: the active pack comes from settings, child
 * theme.css is appended after default, and other files fall back to default.
 * Called from public/index.php before session, plugins, or SecurityHeaders.
 */
final class ThemeAssetServer
{
    public static function isAssetPath(string $path): bool
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '/assets' || str_starts_with($normalized, '/assets/');
    }

    /**
     * If $requestPath is /assets/…, emit the file (or 404) and exit.
     */
    public static function tryServe(Config $config, string $requestPath): bool
    {
        if (!self::isAssetPath($requestPath)) {
            return false;
        }

        $relative = self::relativePath($requestPath);
        $themesPath = (string) $config->get('paths.themes');
        $built = self::build($themesPath, self::activeTheme($config), $relative);
        if ($built === null) {
            Response::notFound('Asset not found');
        }

        self::emit($built['mime'], $built['body'], $built['etag_seed']);
    }

    /**
     * Same serve path as tryServe, when the kernel already knows the active theme.
     */
    public static function serveRelative(Config $config, string $relativePath, string $activeTheme): void
    {
        $themesPath = (string) $config->get('paths.themes');
        $built = self::build($themesPath, $activeTheme, $relativePath);
        if ($built === null) {
            Response::notFound('Asset not found');
        }

        self::emit($built['mime'], $built['body'], $built['etag_seed']);
    }

    /**
     * Resolve a theme asset without sending headers (for tests).
     *
     * @return array{mime: string, body: string, etag_seed: string}|null
     */
    public static function build(string $themesPath, string $activeTheme, string $relativePath): ?array
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $registry = new ThemeRegistry($themesPath);
        $active = $registry->resolve($activeTheme, 'default');
        $defaultFile = self::resolveFile($themesPath, 'default', $relativePath);
        $activeFile = $active !== 'default'
            ? self::resolveFile($themesPath, $active, $relativePath)
            : null;

        if ($relativePath === 'css/theme.css' && $defaultFile !== null && $activeFile !== null && $activeFile !== $defaultFile) {
            $defaultBody = file_get_contents($defaultFile);
            $activeBody = file_get_contents($activeFile);
            if ($defaultBody === false || $activeBody === false) {
                return null;
            }

            return [
                'mime' => 'text/css; charset=utf-8',
                'body' => $defaultBody . "\n" . $activeBody,
                'etag_seed' => $active . '|' . $defaultFile . '|' . filemtime($defaultFile) . '|' . $activeFile . '|' . filemtime($activeFile),
            ];
        }

        $file = $activeFile ?? $defaultFile;
        if ($file === null) {
            return null;
        }

        $body = file_get_contents($file);
        if ($body === false) {
            return null;
        }

        return [
            'mime' => self::mimeForFile($file),
            'body' => $body,
            'etag_seed' => $active . '|' . $file . '|' . filemtime($file),
        ];
    }

    public static function emit(string $mime, string $body, string $etagSeed): never
    {
        $etag = '"' . hash('sha256', $etagSeed) . '"';

        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400, must-revalidate');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && $ifNoneMatch === $etag) {
            http_response_code(304);
            exit;
        }

        echo $body;
        exit;
    }

    public static function activeTheme(Config $config): string
    {
        $themesPath = (string) $config->get('paths.themes');
        $registry = new ThemeRegistry($themesPath);
        $fallback = (string) $config->get('theme.active', 'default');

        return $registry->resolve(self::activeThemeFromSettings($config) ?? '', $fallback);
    }

    private static function activeThemeFromSettings(Config $config): ?string
    {
        if (!$config->isInstalled()) {
            return null;
        }

        try {
            $db = Database::fromConfig($config, readOnly: true);
            $stmt = $db->pdo()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
            $stmt->execute(['key' => ThemeRegistry::SETTING_ACTIVE]);
            $value = $stmt->fetchColumn();

            return is_string($value) ? trim($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function relativePath(string $requestPath): string
    {
        $normalized = '/' . trim($requestPath, '/');
        if ($normalized === '/assets') {
            return '';
        }

        return substr($normalized, strlen('/assets/'));
    }

    private static function resolveFile(string $themesPath, string $theme, string $relativePath): ?string
    {
        $base = realpath($themesPath . '/' . $theme . '/assets');
        if ($base === false) {
            return null;
        }

        $file = realpath($base . '/' . $relativePath);
        if ($file === false || !str_starts_with($file, $base) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    private static function mimeForFile(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }
}
