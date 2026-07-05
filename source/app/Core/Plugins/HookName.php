<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Registered plugin hook names (Phase 4).
 */
final class HookName
{
    public const BOOTSTRAP = 'bootstrap';
    public const ROUTE_REGISTER = 'route.register';
    public const POST_BEFORE_SAVE = 'post.before_save';
    public const POST_AFTER_SAVE = 'post.after_save';
    public const USER_REGISTER = 'user.register';
    public const ADMIN_MENU = 'admin.menu';
    public const THEME_ASSETS = 'theme.assets';
    public const THEME_SCRIPTS = 'theme.scripts';
    public const BOARD_ICONS = 'board.icons';
    public const EDITOR_COMPOSE = 'editor.compose';
    public const POST_FORMAT_IMAGE_HOST = 'post.format.image_host';
    public const CSP_IMG_SRC = 'csp.img_src';
    public const CSP_CONNECT_SRC = 'csp.connect_src';
    public const AVATAR_RESOLVE = 'avatar.resolve';
    public const LAYOUT_FOOTER = 'layout.footer';
    public const HOME_AFTER_BOARDS = 'home.after_boards';
    public const LOCALE_TRANSLATIONS = 'locale.translations';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::BOOTSTRAP,
            self::ROUTE_REGISTER,
            self::POST_BEFORE_SAVE,
            self::POST_AFTER_SAVE,
            self::USER_REGISTER,
            self::ADMIN_MENU,
            self::THEME_ASSETS,
            self::THEME_SCRIPTS,
            self::BOARD_ICONS,
            self::EDITOR_COMPOSE,
            self::POST_FORMAT_IMAGE_HOST,
            self::CSP_IMG_SRC,
            self::CSP_CONNECT_SRC,
            self::AVATAR_RESOLVE,
            self::LAYOUT_FOOTER,
            self::HOME_AFTER_BOARDS,
            self::LOCALE_TRANSLATIONS,
        ];
    }
}