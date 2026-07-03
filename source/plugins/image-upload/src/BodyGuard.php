<?php

declare(strict_types=1);

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
        if (!preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^\)]+)\)/i', $ctx->body, $matches)) {
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
}