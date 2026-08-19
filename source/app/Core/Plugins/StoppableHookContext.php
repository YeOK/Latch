<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Hook argument that can halt remaining listeners (e.g. RegisterContext after reject()).
 */
interface StoppableHookContext
{
    public function isPropagationStopped(): bool;
}
