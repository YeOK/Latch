<?php

declare(strict_types=1);

namespace Latch\Core\BoardIcons;

/**
 * Plugin hook for registering custom board icons.
 * Future plugins implement this and are loaded from paths.plugins.
 */
interface BoardIconProviderInterface
{
    public function register(BoardIconRegistry $registry): void;
}