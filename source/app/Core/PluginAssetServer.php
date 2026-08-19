<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Serves /plugin/{slug}/{file} from plugins/{slug}/assets/ without booting Application.
 *
 * Only static extensions (css/js/images/fonts). Admin JSON and hashed thumb routes stay on the kernel.
 */
final class PluginAssetServer
{
    private const FILE_PATTERN = '/^\/plugin\/([a-z0-9][a-z0-9_-]*)\/([a-zA-Z0-9._-]+\.(css|js|mjs|svg|png|jpe?g|gif|webp|woff2?|ico|map))$/';

    public static function isAssetPath(string $path): bool
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $normalized = '/' . trim(is_string($parsed) && $parsed !== '' ? $parsed : $path, '/');

        return preg_match(self::FILE_PATTERN, $normalized) === 1;
    }

    /**
     * Serve the file when it exists under assets/. Returns false so the kernel can handle other /plugin routes.
     */
    public static function tryServe(Config $config, string $requestPath): bool
    {
        $path = parse_url($requestPath, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $requestPath;
        $normalized = '/' . trim($path, '/');

        if (preg_match(self::FILE_PATTERN, $normalized, $match) !== 1) {
            return false;
        }

        $pluginsPath = (string) $config->get('paths.plugins');
        $built = self::build($pluginsPath, $normalized);
        if ($built === null) {
            return false;
        }

        ThemeAssetServer::emit($built['mime'], $built['body'], $built['etag_seed']);
    }

    /**
     * @return array{mime: string, body: string, etag_seed: string}|null
     */
    public static function build(string $pluginsPath, string $requestPath): ?array
    {
        $normalized = '/' . trim($requestPath, '/');
        if (preg_match(self::FILE_PATTERN, $normalized, $match) !== 1) {
            return null;
        }

        $file = self::resolveFile($pluginsPath, $match[1], $match[2]);
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
            'etag_seed' => $match[1] . '|' . $file . '|' . (string) filemtime($file),
        ];
    }

    private static function resolveFile(string $pluginsPath, string $slug, string $fileName): ?string
    {
        $base = realpath($pluginsPath . '/' . $slug . '/assets');
        if ($base === false) {
            return null;
        }

        $file = realpath($base . '/' . $fileName);
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    private static function mimeForFile(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ico' => 'image/x-icon',
            'map' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
