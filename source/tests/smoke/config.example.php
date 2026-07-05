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
 * Read-only probes (home, login, feed, security checks) run with base_url only.
 * Set member_username + member_password to also exercise topic create + reply.
 * Use a non-admin test member without TOTP enabled.
 *
 * Alternatively export LATCH_TEST_URL or pass `php bin/latch test --smoke --url=https://your-forum`
 */
return [
    'base_url' => 'http://127.0.0.1:8080',

    // Optional — mutating flow (creates a real topic + reply on the target instance)
    'member_username' => '',
    'member_password' => '',
    'board_slug' => 'general',
];