<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Single entry from plugin.json settings_schema.
 */
final class PluginSettingsField
{
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_STRING = 'string';
    public const TYPE_TEXT = 'text';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_STRING_LIST = 'string_list';
    public const TYPE_SECRET_REF = 'secret_ref';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BOOLEAN,
        self::TYPE_STRING,
        self::TYPE_TEXT,
        self::TYPE_INTEGER,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_STRING_LIST,
        self::TYPE_SECRET_REF,
    ];

    /**
     * @param list<array{value: string, label: string}> $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly mixed $default,
        public readonly array $options = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?string $description = null,
        public readonly ?string $secretKey = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function optionValues(): array
    {
        $values = [];
        foreach ($this->options as $option) {
            $value = (string) ($option['value'] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function isWritable(): bool
    {
        return $this->type !== self::TYPE_SECRET_REF;
    }
}