<?php

declare(strict_types=1);

/**
 * Copy to config.local.php for live HTTP smoke tests.
 *
 * Read-only probes (home, login, feed, security checks) run with base_url only.
 * Set member_username + member_password to also exercise topic create + reply.
 * Use a non-admin test member without TOTP enabled.
 */
return [
    'base_url' => 'http://127.0.0.1:8080',

    // Optional — mutating flow (creates a real topic + reply on the target instance)
    'member_username' => '',
    'member_password' => '',
    'board_slug' => 'general',
];