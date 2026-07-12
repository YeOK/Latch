<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;
use RuntimeException;

/**
 * Downloads official Latch-plugins release zips to a temporary file.
 */
final class PluginReleaseDownloader
{
    public function __construct(
        private readonly string $releaseRepo,
        private readonly ?PluginHttpClientInterface $http = null,
    ) {
    }

    public function downloadEntry(PluginCatalogEntry $entry, string $releaseTag): string
    {
        $zipName = $entry->slug . '-' . $entry->version . '.zip';
        $url = $entry->releaseZipUrl($this->releaseRepo, $releaseTag);

        $tempFile = $this->tryDownloadUrl($url);
        if ($tempFile !== null) {
            return $tempFile;
        }

        $resolved = $this->resolveAssetUrlFromGithub($releaseTag, $zipName)
            ?? $this->resolveAssetUrlFromGithub('latest', $zipName);

        if ($resolved === null) {
            throw new RuntimeException(
                "Plugin release zip not found: {$zipName}. "
                . "Check that GitHub release {$releaseTag} exists and includes this asset "
                . '(see github.com/' . $this->releaseRepo . '/releases).',
            );
        }

        $tempFile = $this->tryDownloadUrl($resolved);
        if ($tempFile === null) {
            throw new RuntimeException("Could not download plugin release: {$zipName}");
        }

        return $tempFile;
    }

    public function downloadUrl(string $url): string
    {
        $tempFile = $this->tryDownloadUrl($url);
        if ($tempFile === null) {
            throw new RuntimeException('Plugin release zip not found on GitHub — check catalog release tag');
        }

        return $tempFile;
    }

    private function tryDownloadUrl(string $url): ?string
    {
        $this->assertAllowedUrl($url);

        $response = $this->httpClient()->request('GET', $url);
        if ($response === null) {
            throw new RuntimeException('Could not download plugin release — network error');
        }

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Could not download plugin release (HTTP ' . $response['status'] . ')');
        }

        if ($response['body'] === '') {
            throw new RuntimeException('Downloaded plugin release zip is empty');
        }

        $tempFile = sys_get_temp_dir() . '/latch-plugin-download-' . bin2hex(random_bytes(8)) . '.zip';
        if (file_put_contents($tempFile, $response['body']) === false) {
            throw new RuntimeException('Could not write downloaded plugin zip to disk');
        }

        return $tempFile;
    }

    private function resolveAssetUrlFromGithub(string $releaseTag, string $zipName): ?string
    {
        $apiUrl = $releaseTag === 'latest'
            ? 'https://api.github.com/repos/' . $this->releaseRepo . '/releases/latest'
            : 'https://api.github.com/repos/' . $this->releaseRepo . '/releases/tags/' . rawurlencode($releaseTag);

        $response = $this->httpClient()->request('GET', $apiUrl);
        if ($response === null || $response['status'] === 404) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return null;
        }

        foreach ($data['assets'] ?? [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            if (($asset['name'] ?? '') !== $zipName) {
                continue;
            }

            $downloadUrl = $asset['browser_download_url'] ?? null;
            if (!is_string($downloadUrl) || $downloadUrl === '') {
                continue;
            }

            try {
                $this->assertAllowedUrl($downloadUrl);
            } catch (InvalidArgumentException) {
                continue;
            }

            return $downloadUrl;
        }

        return null;
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Invalid plugin release URL');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || $host !== 'github.com') {
            throw new InvalidArgumentException('Plugin release downloads must use https://github.com');
        }

        $expectedPrefix = '/' . trim($this->releaseRepo, '/') . '/releases/download/';
        if (!str_starts_with($path, $expectedPrefix) || !str_ends_with($path, '.zip')) {
            throw new InvalidArgumentException('Plugin release URL is not from the configured catalog repo');
        }
    }

    private function httpClient(): PluginHttpClientInterface
    {
        return $this->http ?? new PluginHttpClient();
    }
}