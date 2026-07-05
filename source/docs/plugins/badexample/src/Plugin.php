<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\Badexample;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;

/**
 * Audit test fixture — bootstrap is inert; violations live in AuditTrap.php for scanning only.
 */
final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->hooks()->add(HookName::LAYOUT_FOOTER, static function (): string {
            return '<p class="footer-plugin-note muted">badexample is enabled (audit test only — disable me).</p>';
        });
    }
}