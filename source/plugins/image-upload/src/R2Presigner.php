<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ImageUpload;

/**
 * AWS Signature Version 4 presigned PUT URLs for Cloudflare R2.
 */
final class R2Presigner
{
    private const REGION = 'auto';
    private const SERVICE = 's3';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly PluginConfig $config,
    ) {
    }

    public function presignPut(string $objectKey, string $contentType, int $expiresSeconds = 300): string
    {
        $expiresSeconds = max(60, min(900, $expiresSeconds));
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $host = $this->config->r2Host;
        $canonicalUri = '/' . $this->config->bucket . '/' . self::encodeObjectKey($objectKey);

        $credentialScope = $dateStamp . '/' . self::REGION . '/' . self::SERVICE . '/aws4_request';
        $credential = $this->config->accessKeyId . '/' . $credentialScope;

        $queryParams = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'content-type;host',
        ];

        ksort($queryParams);
        $canonicalQueryString = self::buildCanonicalQueryString($queryParams);

        $canonicalHeaders = 'content-type:' . $contentType . "\n"
            . 'host:' . $host . "\n";
        $signedHeaders = 'content-type;host';
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "PUT\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $stringToSign = self::ALGORITHM . "\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = self::signingKey($this->config->secretAccessKey, $dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return 'https://' . $host . $canonicalUri . '?' . $canonicalQueryString . '&X-Amz-Signature=' . $signature;
    }

    private static function encodeObjectKey(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    /**
     * @param array<string, string> $params
     */
    private static function buildCanonicalQueryString(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    private static function signingKey(string $secret, string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', self::REGION, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}