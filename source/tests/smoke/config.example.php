<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


/**
 * Copy to config.local.php for live HTTP smoke tests.
 *
 * Guest probes run with a fresh cookie jar (no login).
 * Set member_* and admin_* to exercise logged-in URL paths.
 * Use non-2FA test accounts only.
 *
 * Alternatively export LATCH_TEST_URL or pass:
 *   php bin/latch test --smoke --url=https://your-forum
 */
$env = static fn (string $key): string => (($v = getenv($key)) !== false && $v !== '') ? $v : '';

return [
    'base_url' => $env('LATCH_TEST_URL') !== '' ? $env('LATCH_TEST_URL') : 'http://127.0.0.1:8080',

    // Guest — read-only paths (home is always probed; add topic/board URLs here)
    'guest_urls' => [
        '/',
    ],

    // Member — optional paths to hit after login (e.g. a topic that regressed for members)
    // CI: set LATCH_SMOKE_MEMBER_USER + LATCH_SMOKE_MEMBER_PASS (and admin_* for admin probes)
    'member_username' => $env('LATCH_SMOKE_MEMBER_USER'),
    'member_password' => $env('LATCH_SMOKE_MEMBER_PASS'),
    'member_urls' => [
        // '/topic/301',
    ],
    'board_slug' => 'general',

    // Admin — optional paths after login (plugins page is always probed when creds set)
    'admin_username' => $env('LATCH_SMOKE_ADMIN_USER'),
    'admin_password' => $env('LATCH_SMOKE_ADMIN_PASS'),
    'admin_urls' => [
        '/admin',
        '/admin/plugins',
    ],
];