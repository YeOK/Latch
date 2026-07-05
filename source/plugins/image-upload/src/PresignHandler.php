<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ImageUpload;

use Latch\Core\Application;
use Latch\Core\Response;

final class PresignHandler
{
    public function __construct(
        private readonly Application $app,
        private readonly PluginConfig $config,
    ) {
    }

    public function handle(): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::json(['error' => 'Invalid form token.'], 403);
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::json(['error' => 'Sign in required.'], 401);
        }

        $contentType = strtolower(trim((string) $this->app->request()->input('content_type', '')));
        $size = (int) $this->app->request()->input('size', 0);
        $filename = trim((string) $this->app->request()->input('filename', ''));

        if (!$this->config->isAllowedContentType($contentType)) {
            Response::json(['error' => 'Unsupported image type. Use JPEG, PNG, GIF, or WebP.'], 400);
        }

        if ($size <= 0 || $size > $this->config->maxBytes) {
            Response::json([
                'error' => 'Image too large (max ' . (int) round($this->config->maxBytes / 1024 / 1024) . ' MB).',
            ], 400);
        }

        $extension = $this->config->extensionForContentType($contentType);
        if ($extension === null) {
            Response::json(['error' => 'Unsupported image type.'], 400);
        }

        $objectKey = $this->config->buildObjectKey((int) $user['id'], $extension);
        $presigner = new R2Presigner($this->config);
        $uploadUrl = $presigner->presignPut($objectKey, $contentType);

        $alt = self::altFromFilename($filename);

        Response::json([
            'upload_url' => $uploadUrl,
            'public_url' => $this->config->publicUrlForKey($objectKey),
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => $contentType,
            ],
            'markdown' => '![' . $alt . '](' . $this->config->publicUrlForKey($objectKey) . ')',
        ]);
    }

    private static function altFromFilename(string $filename): string
    {
        $base = basename($filename);
        $base = preg_replace('/\.[^.]+$/', '', $base) ?? $base;
        $base = trim($base);

        if ($base === '') {
            return 'image';
        }

        if (strlen($base) > 120) {
            $base = substr($base, 0, 120);
        }

        return str_replace(['[', ']', '(', ')'], '', $base);
    }
}