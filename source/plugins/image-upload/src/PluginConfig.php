<?php

declare(strict_types=1);

namespace Latch\Plugins\ImageUpload;

use Latch\Core\Application;

/**
 * Operator credentials from config/local.php → plugins.image_upload (never in DB).
 */
final class PluginConfig
{
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        public readonly string $accountId,
        public readonly string $accessKeyId,
        public readonly string $secretAccessKey,
        public readonly string $bucket,
        public readonly string $publicHost,
        public readonly string $r2Host,
        public readonly int $maxBytes,
        public readonly string $keyPrefix,
    ) {
    }

    public static function fromApp(Application $app): ?self
    {
        $raw = $app->config()->get('plugins.image_upload');
        if (!is_array($raw)) {
            return null;
        }

        $accountId = trim((string) ($raw['account_id'] ?? ''));
        $accessKeyId = trim((string) ($raw['access_key_id'] ?? ''));
        $secret = trim((string) ($raw['secret_access_key'] ?? ''));
        $bucket = trim((string) ($raw['bucket'] ?? ''));
        $publicHost = strtolower(trim((string) ($raw['public_host'] ?? '')));
        $maxMb = (int) ($raw['max_mb'] ?? 8);
        $prefix = trim((string) ($raw['key_prefix'] ?? 'forum/'));

        if ($accountId === '' || $accessKeyId === '' || $secret === '' || $bucket === '' || $publicHost === '') {
            return null;
        }

        if ($maxMb < 1) {
            $maxMb = 1;
        }
        if ($maxMb > 32) {
            $maxMb = 32;
        }

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $r2Host = $accountId . '.r2.cloudflarestorage.com';

        return new self(
            accountId: $accountId,
            accessKeyId: $accessKeyId,
            secretAccessKey: $secret,
            bucket: $bucket,
            publicHost: $publicHost,
            r2Host: $r2Host,
            maxBytes: $maxMb * 1024 * 1024,
            keyPrefix: $prefix,
        );
    }

    public function isAllowedContentType(string $contentType): bool
    {
        return isset(self::ALLOWED_TYPES[strtolower(trim($contentType))]);
    }

    public function extensionForContentType(string $contentType): ?string
    {
        return self::ALLOWED_TYPES[strtolower(trim($contentType))] ?? null;
    }

    public function isAllowedPublicHost(string $host): bool
    {
        return strtolower(trim($host)) === $this->publicHost;
    }

    public function publicUrlForKey(string $objectKey): string
    {
        $segments = explode('/', $objectKey);

        return 'https://' . $this->publicHost . '/' . implode('/', array_map('rawurlencode', $segments));
    }

    public function buildObjectKey(int $userId, string $extension): string
    {
        $uuid = bin2hex(random_bytes(16));

        return $this->keyPrefix . $userId . '/' . $uuid . '.' . $extension;
    }
}