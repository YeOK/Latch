<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Shared SVG markup denylist for branding uploads and plugin board icons.
 */
final class SvgSafety
{
    /**
     * @return list<string>
     */
    public static function blockedNeedles(): array
    {
        return [
            '<script',
            'javascript:',
            '<foreignobject',
            '<?php',
            '<!entity',
        ];
    }

    public static function containsDisallowedMarkup(string $svg): bool
    {
        $lower = strtolower($svg);
        foreach (self::blockedNeedles() as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return preg_match('/on(load|error|click|mouse\w*|focus|blur|change|submit|input)\s*=/i', $svg) === 1;
    }
}
