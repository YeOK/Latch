<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Cache;

/**
 * Runtime services plugin collect hooks need for guest cache behaviour.
 */
interface PluginCollectContext
{
    public function guestFragmentCacheEnabled(): bool;

    public function cache(): Cache;

    public function cacheTtlSeconds(): int;

    public function resolvedLocale(): string;

    public function cspNonce(): string;
}