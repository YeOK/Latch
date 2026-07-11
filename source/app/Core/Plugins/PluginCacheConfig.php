<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;

/**
 * Parsed plugin.json cache object (guest page / fragment behaviour).
 */
final class PluginCacheConfig
{
    public const GUEST_PAGE_BAKE = 'bake';
    public const GUEST_PAGE_FRAGMENT = 'fragment';
    public const GUEST_PAGE_CLIENT = 'client';
    public const GUEST_PAGE_BYPASS = 'bypass';

    /** @var list<string> */
    public const GUEST_PAGES = [
        self::GUEST_PAGE_BAKE,
        self::GUEST_PAGE_FRAGMENT,
        self::GUEST_PAGE_CLIENT,
        self::GUEST_PAGE_BYPASS,
    ];

    /**
     * @param list<string> $invalidateOn
     */
    public function __construct(
        public readonly string $guestPage = self::GUEST_PAGE_BAKE,
        public readonly array $invalidateOn = ['site'],
        public readonly ?string $fragmentHook = null,
        public readonly ?string $clientRoute = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $manifestData
     */
    public static function fromManifestData(array $manifestData): self
    {
        $cache = $manifestData['cache'] ?? null;
        if ($cache === null) {
            return self::default();
        }

        if (!is_array($cache)) {
            throw new InvalidArgumentException('Manifest cache must be an object');
        }

        $guestPage = trim((string) ($cache['guest_page'] ?? self::GUEST_PAGE_BAKE));
        if ($guestPage === '') {
            $guestPage = self::GUEST_PAGE_BAKE;
        }

        if (!in_array($guestPage, self::GUEST_PAGES, true)) {
            throw new InvalidArgumentException("Unknown cache.guest_page '{$guestPage}'");
        }

        $invalidateOn = [];
        $rawInvalidate = $cache['invalidate_on'] ?? ['site'];
        if (is_array($rawInvalidate)) {
            foreach ($rawInvalidate as $entry) {
                if (!is_string($entry)) {
                    continue;
                }

                $entry = trim($entry);
                if ($entry === 'site' || $entry === 'plugin') {
                    $invalidateOn[] = $entry;
                }
            }
        }

        if ($invalidateOn === []) {
            $invalidateOn = ['site'];
        }

        $fragmentHook = isset($cache['fragment']) ? trim((string) $cache['fragment']) : null;
        if ($fragmentHook === '') {
            $fragmentHook = null;
        }

        $clientRoute = isset($cache['client']) ? trim((string) $cache['client']) : null;
        if ($clientRoute === '') {
            $clientRoute = null;
        }

        return new self(
            guestPage: $guestPage,
            invalidateOn: array_values(array_unique($invalidateOn)),
            fragmentHook: $fragmentHook,
            clientRoute: $clientRoute,
        );
    }

    public function invalidatesOnSite(): bool
    {
        return in_array('site', $this->invalidateOn, true);
    }

    public function invalidatesOnPlugin(): bool
    {
        return in_array('plugin', $this->invalidateOn, true);
    }

    public function isBypass(): bool
    {
        return $this->guestPage === self::GUEST_PAGE_BYPASS;
    }

    public function isFragment(): bool
    {
        return $this->guestPage === self::GUEST_PAGE_FRAGMENT;
    }

    public function isClient(): bool
    {
        return $this->guestPage === self::GUEST_PAGE_CLIENT;
    }
}