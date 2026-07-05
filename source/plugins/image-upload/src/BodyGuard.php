<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ImageUpload;

use Latch\Core\Plugins\PostSaveContext;

/**
 * Reject markdown images from hosts outside the configured CDN.
 */
final class BodyGuard
{
    public function __construct(
        private readonly PluginConfig $config,
    ) {
    }

    public function validate(PostSaveContext $ctx): ?string
    {
        $body = $this->bodyWithoutCodeSamples($ctx->body);
        if (!preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^\)]+)\)/i', $body, $matches)) {
            return null;
        }

        foreach ($matches[1] as $url) {
            if (!is_string($url)) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || !$this->config->isAllowedPublicHost($host)) {
                return 'Post images must use ' . $this->config->publicHost . ' (use Insert image in the editor).';
            }
        }

        return null;
    }

    /**
     * Strip fenced and inline code so documentation examples do not trip the image host check.
     */
    private function bodyWithoutCodeSamples(string $body): string
    {
        $body = (string) preg_replace('/```.*?```/s', '', $body);
        $body = (string) preg_replace('/`[^`]*`/', '', $body);

        return $body;
    }
}