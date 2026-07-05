<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Renders QR codes as inline SVG (no external services or xmlwriter).
 */
final class QrCode
{
    /** White border required by the QR spec — scanners fail without it. */
    private const QUIET_ZONE = 4;

    public function svg(string $payload, int $size = 280): string
    {
        $qr = Encoder::encode($payload, ErrorCorrectionLevel::M());
        $matrix = $qr->getMatrix();
        $modules = $matrix->getWidth();
        $total = $modules + (2 * self::QUIET_ZONE);
        $rects = [];

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }

                $rects[] = sprintf(
                    '<rect x="%d" y="%d" width="1" height="1"/>',
                    $x + self::QUIET_ZONE,
                    $y + self::QUIET_ZONE,
                );
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%3$d" height="%3$d"'
            . ' shape-rendering="crispEdges" role="img" aria-label="Scan with authenticator app">'
            . '<rect width="%1$d" height="%2$d" fill="#ffffff"/>'
            . '<g fill="#000000">%4$s</g></svg>',
            $total,
            $total,
            $size,
            implode('', $rects),
        );
    }
}