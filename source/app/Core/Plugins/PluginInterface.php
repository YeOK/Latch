<?php

declare(strict_types=1);

namespace Latch\Core\Plugins;

interface PluginInterface
{
    public function register(PluginContext $context): void;
}