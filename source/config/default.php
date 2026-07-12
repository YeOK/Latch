<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


/**
 * Default configuration — copied/adjusted by the installer.
 * Secrets and environment overrides go in config/local.php (not committed).
 */
return [
    'app' => [
        'version' => '0.4.5.1',
    ],
    'site' => [
        'name' => 'Latch',
        'tagline' => 'Fast, secure discussions',
        'url' => 'http://localhost',
    ],
    'forum' => [
        // When true, guests cannot read boards unless a board allows it (Phase 2).
        'members_only' => false,
        'allow_registration' => true,
        'posts_per_page' => 20,
        'topic_pagination_threshold' => 50,
        'topics_per_page' => 30,
        'rss_items_limit' => 50,
        'sitemap_topics_limit' => 5000,
    ],
    'input' => [
        'username_min' => 3,
        'username_max' => 32,
        'post_body_max' => 65535,
        'topic_title_min' => 1,
        'topic_title_max' => 255,
        'bio_max' => 500,
        'board_name_max' => 80,
        'board_description_max' => 500,
        'search_query_max' => 200,
        'report_detail_max' => 500,
        'site_name_max' => 80,
        'site_tagline_max' => 160,
        'footer_about_max' => 500,
        'email_max' => 254,
        'password_max' => 128,
    ],
    'security' => [
        'session_name' => 'latch_session',
        'login_max_attempts' => 10,
        'login_lockout_minutes' => 15,
        'password_min_length' => 8,
        // Base64-encoded 32-byte key for encrypting TOTP secrets (set in local.php for production).
        'encryption_key' => '',
        // Roles that must enrol TOTP before sign-in completes.
        'totp_required_roles' => ['admin'],
        // When behind Cloudflare (CF-Ray header present), log rate-limit by CF-Connecting-IP.
        'trust_cloudflare' => true,
        // When true, X-Forwarded-Proto is trusted without requiring CF-Ray (only if behind a trusted proxy).
        'trust_forwarded_proto' => false,
        'trust_x_forwarded_for' => false,
        // Cloudflare Turnstile — set in config/local.php (free at dash.cloudflare.com → Turnstile).
        'turnstile_site_key' => '',
        'turnstile_secret_key' => '',
        'registration_max_per_ip_hour' => 3,
    ],
    'cache' => [
        'enabled' => true,
        'ttl_seconds' => 120,
    ],
    'logs' => [
        'server_logs_enabled' => false,
        'max_lines_per_request' => 200,
        'tail_window_bytes' => 2_097_152,
        'search_scan_bytes' => 524_288,
        'mask_emails' => false,
        'max_allowed_roots' => 5,
        'allowed_roots' => [],
        'security_event_types' => [
            'login_fail',
            'login_success',
            'login_banned',
            'login_deleted',
            'login_totp_fail',
            'login_totp_challenge',
            'login_totp_setup_required',
            'oidc_fail',
            'logout',
            'ban',
            'unban',
            'founder_block',
            'password_reset_request',
            'password_reset_complete',
            'password_change',
            'email_change_request',
            'email_change_complete',
            'oauth_app_revoke',
            'account_delete',
            'report',
            'quarantine_lift',
            'quarantine_apply',
            'spam_honeypot',
            'registration_blocked',
            'vote_rate_limit',
            'mail_send_failed',
            'totp_enabled',
            'totp_disabled',
            'totp_recovery_regenerated',
        ],
        'sources' => [],
    ],
    'mail' => [
        'enabled' => true,
        'transport' => 'msmtp',
        'from_email' => 'noreply@localhost',
        'from_name' => 'Latch',
        'msmtp_config' => '',
    ],
    'oidc' => [
        'google' => [
            'client_id' => '',
            'client_secret' => '',
        ],
        'github' => [
            'client_id' => '',
            'client_secret' => '',
        ],
    ],
    'plugin_catalog' => [
        'catalog_url' => 'https://raw.githubusercontent.com/YeOK/Latch-plugins/main/catalog.json',
        'release_repo' => 'YeOK/Latch-plugins',
        'cache_ttl_seconds' => 3600,
    ],
    'paths' => [
        'storage' => dirname(__DIR__) . '/storage',
        'themes' => dirname(__DIR__) . '/themes',
        'plugins' => dirname(__DIR__) . '/plugins',
    ],
    'theme' => [
        'active' => 'default',
        // Optional prefix; Latch appends theme file mtimes automatically (see Application::assetVersion).
        'asset_version' => '',
    ],
    'i18n' => [
        'default_locale' => 'en',
    ],
    'database' => [
        'driver' => 'sqlite',
        'path' => dirname(__DIR__) . '/storage/database/latch.sqlite',
        // SQLite tuning — override in config/local.php as needed.
        'sqlite' => [
            // Milliseconds to wait on a locked DB before SQLITE_BUSY (0 = fail immediately).
            'busy_timeout_ms' => 5000,
            // Page cache in KiB (8192 = 8 MiB). 0 leaves the SQLite default (~2 MiB).
            'cache_size_kib' => 8192,
            // Memory-mapped I/O in bytes (0 = disabled). Large read-heavy sites may try 268435456 (256 MiB).
            'mmap_size' => 0,
        ],
    ],
];