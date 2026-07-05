<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


/**
 * Router for PHP's built-in server (local dev only — not installed under public/).
 *
 *   ./scripts/dev-server.sh
 *   php -S 127.0.0.1:8080 -t source/public scripts/router-dev.php
 */
$public = dirname(__DIR__) . '/source/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = $public . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require $public . '/index.php';