<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\WordFilter;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;
use Latch\Core\Plugins\PostSaveContext;

final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $storageRoot = (string) $context->app()->config()->get('paths.storage');
        $settings = Settings::load($context->path(), $storageRoot, $context->manifest());
        $filter = new WordFilter($settings);

        $context->hooks()->add(
            HookName::POST_BEFORE_SAVE,
            static function (PostSaveContext $ctx) use ($filter): void {
                $reason = $filter->process($ctx);
                if ($reason !== null) {
                    $ctx->reject($reason);
                }
            },
            10,
        );
    }
}