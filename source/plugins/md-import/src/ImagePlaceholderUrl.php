<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\MdImport;

/**
 * CDN placeholder for imported images — reads image-upload secrets from local.php only.
 */
final class ImagePlaceholderUrl
{
    /**
     * @param mixed $imageUploadConfig plugins.image_upload from config/local.php
     */
    public static function resolve(mixed $imageUploadConfig): string
    {
        if (!is_array($imageUploadConfig)) {
            return self::fallback();
        }

        $publicHost = strtolower(trim((string) ($imageUploadConfig['public_host'] ?? '')));
        if ($publicHost === '') {
            return self::fallback();
        }

        return 'https://' . $publicHost . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
    }

    public static function fallback(): string
    {
        return 'https://md-import.invalid' . MarkdownImport::IMAGE_PLACEHOLDER_PATH;
    }
}