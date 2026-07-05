<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Oidc;

use Latch\Core\Config;
use Latch\Models\SettingRepository;

final class OidcConfig
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_GITHUB = 'github';

    /** @var list<string> */
    private const PROVIDERS = [self::PROVIDER_GOOGLE, self::PROVIDER_GITHUB];

    public function __construct(
        private readonly Config $config,
        private readonly SettingRepository $settings,
    ) {
    }

    public static function normalizeProvider(string $provider): ?string
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, self::PROVIDERS, true) ? $provider : null;
    }

    /**
     * @return list<string>
     */
    public function enabledProviders(): array
    {
        $enabled = [];
        foreach (self::PROVIDERS as $provider) {
            if ($this->isEnabled($provider)) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }

    public function isEnabled(string $provider): bool
    {
        if (!$this->isConfigured($provider)) {
            return false;
        }

        return $this->settings->getBool($this->settingKey($provider, 'enabled'));
    }

    public function isConfigured(string $provider): bool
    {
        return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '';
    }

    public function clientId(string $provider): string
    {
        return trim((string) $this->config->get("oidc.{$provider}.client_id", ''));
    }

    public function clientSecret(string $provider): string
    {
        return trim((string) $this->config->get("oidc.{$provider}.client_secret", ''));
    }

    public function redirectUri(string $provider): string
    {
        $siteUrl = rtrim((string) $this->config->get('site.url', ''), '/');
        if ($siteUrl === '') {
            return '';
        }

        return $siteUrl . '/auth/oidc/' . $provider . '/callback';
    }

    public function displayName(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_GOOGLE => 'Google',
            self::PROVIDER_GITHUB => 'GitHub',
            default => ucfirst($provider),
        };
    }

    private function settingKey(string $provider, string $suffix): string
    {
        return 'oidc_' . $provider . '_' . $suffix;
    }
}