<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Translation keys for session flash messages (Phase 8 PHP i18n).
 *
 * Migrate controllers from hardcoded strings to Application::flashTrans():
 *   $this->app->flashTrans('error', FlashMessage::INVALID_CSRF);
 */
final class FlashMessage
{
    public const INVALID_CSRF = 'flash.invalid_csrf';
    public const SIGN_IN_REQUIRED = 'flash.sign_in_required';
    public const NOTIFICATIONS_MARKED_READ = 'flash.notifications_marked_read';
    public const POST_UPDATED = 'flash.post_updated';
    public const NO_CHANGES = 'flash.no_changes';
    public const REPLY_PENDING = 'flash.reply_pending';
    public const TOPIC_REMOVED = 'flash.topic_removed';
    public const TOPIC_LOCKED = 'flash.topic_locked';
    public const CANNOT_REPLY = 'flash.cannot_reply';
    public const RATE_LIMITED = 'flash.rate_limited';
    public const TOTP_ENABLED = 'flash.totp_enabled';
    public const TOTP_DISABLED = 'flash.totp_disabled';
}