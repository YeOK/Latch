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
 * Operator-controlled branding assets (logo, favicon, OG image).
 */
final class SiteBranding
{
    public const MODE_LATCH = 'latch';
    public const MODE_CUSTOM = 'custom';
    public const MODE_TEXT_ONLY = 'text_only';

    private const SETTING_MODE = 'brand_mode';
    private const MAX_LOGO_BYTES = 524_288;
    private const MAX_FAVICON_BYTES = 262_144;
    private const MAX_OG_BYTES = 1_048_576;

    /** @var list<string> */
    private const ALLOWED_MODES = [self::MODE_LATCH, self::MODE_CUSTOM, self::MODE_TEXT_ONLY];

    /** @var array<string, array{setting: string, basename: string, route: string, exts: list<string>, max_bytes: int, raster_only?: bool}> */
    private const ASSETS = [
        'logo' => [
            'setting' => 'brand_logo_ext',
            'basename' => 'logo',
            'route' => 'logo',
            'exts' => ['svg', 'png'],
            'max_bytes' => self::MAX_LOGO_BYTES,
        ],
        'logo_dark' => [
            'setting' => 'brand_logo_dark_ext',
            'basename' => 'logo-dark',
            'route' => 'logo-dark',
            'exts' => ['svg', 'png'],
            'max_bytes' => self::MAX_LOGO_BYTES,
        ],
        'favicon' => [
            'setting' => 'brand_favicon_ext',
            'basename' => 'favicon',
            'route' => 'favicon',
            'exts' => ['svg', 'png'],
            'max_bytes' => self::MAX_FAVICON_BYTES,
        ],
        'og' => [
            'setting' => 'brand_og_ext',
            'basename' => 'og',
            'route' => 'og',
            'exts' => ['png', 'jpg', 'jpeg'],
            'max_bytes' => self::MAX_OG_BYTES,
            'raster_only' => true,
        ],
    ];

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
        return $this->hasAsset('logo');
    }

    public function hasUploadedLogoDark(): bool
    {
        return $this->hasAsset('logo_dark');
    }

    public function hasFavicon(): bool
    {
        return $this->hasAsset('favicon');
    }

    public function hasOgImage(): bool
    {
        return $this->hasAsset('og');
    }

    public function showMark(): bool
    {
        return match ($this->mode()) {
            self::MODE_TEXT_ONLY => false,
            self::MODE_LATCH, self::MODE_CUSTOM => true,
            default => true,
        };
    }

    public function usesLatchBuiltinMark(): bool
    {
        return $this->mode() === self::MODE_LATCH;
    }

    public function logoUrl(): ?string
    {
        if (!$this->showMark() || $this->mode() === self::MODE_LATCH) {
            return null;
        }

        return $this->assetUrl('logo') ?? '/assets/img/latch-logo.svg';
    }

    public function logoDarkUrl(): ?string
    {
        if (!$this->showMark() || $this->mode() !== self::MODE_CUSTOM) {
            return null;
        }

        return $this->assetUrl('logo_dark');
    }

    public function faviconUrl(): ?string
    {
        return $this->assetUrl('favicon');
    }

    public function faviconMime(): ?string
    {
        return $this->hasFavicon() ? $this->mimeForAsset('favicon') : null;
    }

    public function ogUrl(): ?string
    {
        return $this->assetUrl('og');
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function ogDimensions(): ?array
    {
        $path = $this->assetPath('og');
        if ($path === null) {
            return null;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    public function enrichSeo(array $seo, string $siteUrl): array
    {
        if (!$this->hasOgImage()) {
            return $seo;
        }

        $url = $this->assetUrl('og');
        if ($url === null) {
            return $seo;
        }

        $seo['image'] = SeoMeta::absoluteUrl($siteUrl, $url);
        $dims = $this->ogDimensions();
        if ($dims !== null) {
            $seo['image_width'] = $dims['width'];
            $seo['image_height'] = $dims['height'];
        }
        $mime = $this->mimeForAsset('og');
        if ($mime !== null) {
            $seo['image_type'] = $mime;
        }

        return $seo;
    }

    public function logoPath(): ?string
    {
        return $this->assetPath('logo');
    }

    public function mimeForServe(string $route): ?string
    {
        $asset = $this->assetForRoute($route);

        return $asset !== null ? $this->mimeForAsset($asset) : null;
    }

    public function pathForRoute(string $route): ?string
    {
        $asset = $this->assetForRoute($route);

        return $asset !== null ? $this->assetPath($asset) : null;
    }

    /**
     * @param array<string, mixed> $upload
     */
    public function saveUpload(array $upload): ?string
    {
        return $this->saveAssetUpload('logo', $upload);
    }

    /**
     * @param array<string, mixed> $upload
     */
    public function saveAssetUpload(string $asset, array $upload): ?string
    {
        if (!isset(self::ASSETS[$asset])) {
            return 'Unknown branding asset.';
        }

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return $this->uploadErrorLabel($asset) . ' upload failed.';
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'Invalid ' . $this->uploadErrorLabel($asset) . ' upload.';
        }

        $config = self::ASSETS[$asset];
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > $config['max_bytes']) {
            return $this->uploadErrorLabel($asset) . ' exceeds size limit.';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $ext = $this->extensionForMime($mime, $config['exts'], (bool) ($config['raster_only'] ?? false));
        if ($ext === null) {
            return $this->uploadErrorLabel($asset) . ' has an unsupported file type.';
        }

        $raw = (string) file_get_contents($tmp);
        if ($raw === '') {
            return 'Could not read uploaded file.';
        }

        return $this->persistAsset($asset, $raw, $ext, $tmp);
    }

    public function persistLogo(string $raw, string $ext): ?string
    {
        return $this->persistAsset('logo', $raw, $ext);
    }

    public function persistAsset(string $asset, string $raw, string $ext, ?string $tmpPath = null): ?string
    {
        if (!isset(self::ASSETS[$asset])) {
            return 'Unknown branding asset.';
        }

        $config = self::ASSETS[$asset];
        $ext = strtolower($ext);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        if (!in_array($ext, $config['exts'], true) && !($ext === 'jpg' && in_array('jpeg', $config['exts'], true))) {
            return $this->uploadErrorLabel($asset) . ' has an unsupported file type.';
        }

        if ($ext === 'svg') {
            $svgError = $this->validateSvg($raw);
            if ($svgError !== null) {
                return $svgError;
            }
        } elseif ($tmpPath !== null) {
            $imageInfo = @getimagesize($tmpPath);
            $expected = match ($ext) {
                'png' => IMAGETYPE_PNG,
                'jpg' => IMAGETYPE_JPEG,
                default => null,
            };
            if ($imageInfo === false || $expected === null || ($imageInfo[2] ?? 0) !== $expected) {
                return 'Invalid image file.';
            }
        }

        if (!$this->ensureDirectory()) {
            return 'Could not create branding storage directory.';
        }

        $this->removeAssetFiles($asset);

        $dest = $this->directory() . '/' . $config['basename'] . '.' . $ext;
        if (file_put_contents($dest, $raw) === false) {
            return 'Could not save ' . $this->uploadErrorLabel($asset) . '.';
        }

        $this->settings->set($config['setting'], $ext);
        if ($asset === 'logo' && $this->mode() !== self::MODE_TEXT_ONLY) {
            $this->settings->set(self::SETTING_MODE, self::MODE_CUSTOM);
        }

        return null;
    }

    public function removeLogo(): void
    {
        $this->removeAsset('logo');
    }

    public function removeAsset(string $asset): void
    {
        if (!isset(self::ASSETS[$asset])) {
            return;
        }

        $this->removeAssetFiles($asset);
        $this->settings->set(self::ASSETS[$asset]['setting'], '');
    }

    private function hasAsset(string $asset): bool
    {
        return $this->assetPath($asset) !== null;
    }

    private function assetUrl(string $asset): ?string
    {
        $path = $this->assetPath($asset);
        if ($path === null) {
            return null;
        }

        $route = self::ASSETS[$asset]['route'];

        return '/branding/' . $route . '?v=' . (int) filemtime($path);
    }

    private function assetPath(string $asset): ?string
    {
        if (!isset(self::ASSETS[$asset])) {
            return null;
        }

        $ext = strtolower(trim((string) $this->settings->get(self::ASSETS[$asset]['setting'], '')));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        if (!in_array($ext, self::ASSETS[$asset]['exts'], true)
            && !($ext === 'jpg' && in_array('jpeg', self::ASSETS[$asset]['exts'], true))) {
            return null;
        }

        $file = $this->directory() . '/' . self::ASSETS[$asset]['basename'] . '.' . $ext;
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

    private function mimeForAsset(string $asset): ?string
    {
        $path = $this->assetPath($asset);
        if ($path === null) {
            return null;
        }

        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    private function assetForRoute(string $route): ?string
    {
        foreach (self::ASSETS as $key => $config) {
            if ($config['route'] === $route) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param list<string> $allowed
     */
    private function extensionForMime(string|false $mime, array $allowed, bool $rasterOnly): ?string
    {
        if ($mime === false) {
            return null;
        }

        if ($rasterOnly) {
            return match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => null,
            };
        }

        return match ($mime) {
            'image/svg+xml' => in_array('svg', $allowed, true) ? 'svg' : null,
            'image/png' => in_array('png', $allowed, true) ? 'png' : null,
            'image/jpeg' => (in_array('jpg', $allowed, true) || in_array('jpeg', $allowed, true)) ? 'jpg' : null,
            default => null,
        };
    }

    private function uploadErrorLabel(string $asset): string
    {
        return match ($asset) {
            'logo' => 'Logo',
            'logo_dark' => 'Dark logo',
            'favicon' => 'Favicon',
            'og' => 'OG image',
            default => 'File',
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

    private function removeAssetFiles(string $asset): void
    {
        $config = self::ASSETS[$asset];
        foreach ($config['exts'] as $ext) {
            if ($ext === 'jpeg') {
                continue;
            }
            $file = $this->directory() . '/' . $config['basename'] . '.' . $ext;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (in_array('jpeg', $config['exts'], true) || in_array('jpg', $config['exts'], true)) {
            $jpg = $this->directory() . '/' . $config['basename'] . '.jpg';
            if (is_file($jpg)) {
                @unlink($jpg);
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
                return 'SVG contains disallowed content.';
            }
        }

        if (!str_contains($lower, '<svg')) {
            return 'File does not look like a valid SVG.';
        }

        return null;
    }
}