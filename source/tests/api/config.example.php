<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


/**
 * Copy to config.local.php and fill in OAuth client credentials.
 * config.local.php is gitignored — safe for production secrets on the server.
 *
 * Create a client on the server:
 *   ~/Documents/latch/scripts/setup-api-test-client.sh
 * or manually:
 *   sudo -u apache php bin/latch api-client create --name="Local API Harness" \
 *     --redirect=https://forum.example.com/oauth/cli-callback \
 *     --scopes=read,messages:read,messages:write
 */
return [
    'base_url' => 'https://forum.example.com',
    'client_id' => 'latch_xxxxxxxxxxxxxxxx',
    'client_secret' => 'your-client-secret-here',

    // Must match --redirect when creating the OAuth client.
    'redirect_uri' => 'https://forum.example.com/oauth/cli-callback',

    // Optional: member username to message during write tests (must accept DMs from you).
    'message_recipient' => '',

    // Optional: assert guest-visible boards (read API harness only)
    'expect_guest_board_slugs' => ['news'],
];