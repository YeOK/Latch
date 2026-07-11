<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\Dbexample;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->hooks()->add(HookName::LAYOUT_FOOTER, function () use ($context): string {
            $db = $context->database();
            if ($db === null) {
                return '<p class="footer-plugin-note muted">Database example plugin active (no plugin DB).</p>';
            }

            $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM event_log')->fetchColumn();

            return '<p class="footer-plugin-note muted">Database example plugin active — '
                . $count
                . ' event_log row(s) in plugin.sqlite.</p>';
        });
    }
}