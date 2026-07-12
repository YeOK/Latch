<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\SettingRepository;

/**
 * Operator-controlled site logo (header, footer, favicon when custom).
 */
final class SiteBranding
{
    public const MODE_LATCH = 'latch';
    public const MODE_CUSTOM = 'custom';
    public const MODE_TEXT_ONLY = 'text_only';

    private const SETTING_MODE = 'brand_mode';
    private const SETTING_LOGO_EXT = 'brand_logo_ext';
    private const MAX_BYTES = 524_288;

    /** @var list<string> */
    private const ALLOWED_MODES = [self::MODE_LATCH, self::MODE_CUSTOM, self::MODE_TEXT_ONLY];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly string $storagePath,
    ) {
    }

    public function directory(): string
    {
        return rtrim($this->storagePath, '/') . '/branding';
    }

    public function mode(): string
    {
        $stored = trim((string) $this->settings->get(self::SETTING_MODE, ''));
        if ($stored !== '' && in_array($stored, self::ALLOWED_MODES, true)) {
            return $stored;
        }

        $siteName = trim((string) $this->settings->get('site_name', 'Latch'));

        return strcasecmp($siteName, 'Latch') === 0 ? self::MODE_LATCH : self::MODE_CUSTOM;
    }

    public function setMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            return 'Invalid brand mode.';
        }

        $this->settings->set(self::SETTING_MODE, $mode);

        return null;
    }

    public function hasUploadedLogo(): bool
    {
        return $this->logoPath() !== null;
    }

    public function showMark(): bool
    {
        return match ($this->mode()) {
            self::MODE_TEXT_ONLY => false,
            self::MODE_LATCH => true,
            self::MODE_CUSTOM => true,
            default => true,
        };
    }

    public function usesLatchBuiltinMark(): bool
    {
        return $this->mode() === self::MODE_LATCH;
    }

    public function logoUrl(): ?string
    {
        if (!$this->showMark()) {
            return null;
        }

        if ($this->mode() === self::MODE_LATCH) {
            return null;
        }

        if ($this->hasUploadedLogo()) {
            $path = $this->logoPath();
            $mtime = $path !== null ? (int) filemtime($path) : 0;

            return '/branding/logo?v=' . $mtime;
        }

        return '/assets/img/latch-logo.svg';
    }

    public function faviconUrl(): ?string
    {
        if ($this->mode() === self::MODE_CUSTOM && $this->hasUploadedLogo()) {
            $path = $this->logoPath();
            $mtime = $path !== null ? (int) filemtime($path) : 0;

            return '/branding/logo?v=' . $mtime;
        }

        return null;
    }

    public function faviconMime(): ?string
    {
        return $this->faviconUrl() !== null ? $this->mimeForServe() : null;
    }

    public function logoPath(): ?string
    {
        $ext = strtolower(trim((string) $this->settings->get(self::SETTING_LOGO_EXT, '')));
        if ($ext !== 'svg' && $ext !== 'png') {
            return null;
        }

        $file = $this->directory() . '/logo.' . $ext;
        if (!is_file($file)) {
            return null;
        }

        $real = realpath($file);
        $base = realpath($this->directory());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * @param array<string, mixed> $upload
     */
    public function saveUpload(array $upload): ?string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'Logo upload failed.';
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'Invalid logo upload.';
        }

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return 'Logo must be 512 KB or smaller.';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $ext = match ($mime) {
            'image/svg+xml' => 'svg',
            'image/png' => 'png',
            default => null,
        };
        if ($ext === null) {
            return 'Logo must be SVG or PNG.';
        }

        $raw = (string) file_get_contents($tmp);
        if ($raw === '') {
            return 'Could not read uploaded logo.';
        }

        if ($ext === 'png') {
            $imageInfo = @getimagesize($tmp);
            if ($imageInfo === false || ($imageInfo[2] ?? 0) !== IMAGETYPE_PNG) {
                return 'Invalid PNG image.';
            }
        }

        return $this->persistLogo($raw, $ext);
    }

    public function persistLogo(string $raw, string $ext): ?string
    {
        $ext = strtolower($ext);
        if ($ext !== 'svg' && $ext !== 'png') {
            return 'Logo must be SVG or PNG.';
        }

        if ($ext === 'svg') {
            $svgError = $this->validateSvg($raw);
            if ($svgError !== null) {
                return $svgError;
            }
        }

        if (!$this->ensureDirectory()) {
            return 'Could not create branding storage directory.';
        }

        $this->removeLogoFiles();

        $dest = $this->directory() . '/logo.' . $ext;
        if (file_put_contents($dest, $raw) === false) {
            return 'Could not save logo.';
        }

        $this->settings->set(self::SETTING_LOGO_EXT, $ext);
        if ($this->mode() !== self::MODE_TEXT_ONLY) {
            $this->settings->set(self::SETTING_MODE, self::MODE_CUSTOM);
        }

        return null;
    }

    public function removeLogo(): void
    {
        $this->removeLogoFiles();
        $this->settings->set(self::SETTING_LOGO_EXT, '');
    }

    public function mimeForServe(): ?string
    {
        $path = $this->logoPath();
        if ($path === null) {
            return null;
        }

        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => null,
        };
    }

    private function ensureDirectory(): bool
    {
        $dir = $this->directory();
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return mkdir($dir, 02770, true) || is_dir($dir);
    }

    private function removeLogoFiles(): void
    {
        foreach (['svg', 'png'] as $ext) {
            $file = $this->directory() . '/logo.' . $ext;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function validateSvg(string $raw): ?string
    {
        $lower = strtolower($raw);
        $blocked = [
            '<script',
            'javascript:',
            '<foreignobject',
            '<?php',
            '<!entity',
            'onload=',
            'onerror=',
            'onclick=',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($lower, $needle)) {
                return 'SVG logo contains disallowed content.';
            }
        }

        if (!str_contains($lower, '<svg')) {
            return 'File does not look like a valid SVG.';
        }

        return null;
    }
}