<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Parsed settings_schema and secrets_schema from plugin.json.
 */
final class PluginSettingsSchema
{
    /**
     * @param list<PluginSettingsField> $settingsFields
     * @param list<PluginSecretField> $secretFields
     */
    public function __construct(
        public readonly array $settingsFields,
        public readonly array $secretFields,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param array<string, mixed> $manifestData
     */
    public static function fromManifestData(array $manifestData): self
    {
        $settingsRaw = $manifestData['settings_schema'] ?? [];
        $secretsRaw = $manifestData['secrets_schema'] ?? [];

        $settingsFields = [];
        if (is_array($settingsRaw)) {
            foreach ($settingsRaw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $field = self::parseSettingsField($entry);
                if ($field !== null) {
                    $settingsFields[] = $field;
                }
            }
        }

        $secretFields = [];
        if (is_array($secretsRaw)) {
            foreach ($secretsRaw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $field = self::parseSecretField($entry);
                if ($field !== null) {
                    $secretFields[] = $field;
                }
            }
        }

        return new self($settingsFields, $secretFields);
    }

    public function hasAdminUi(): bool
    {
        return $this->settingsFields !== [] || $this->secretFields !== [];
    }

    public function fieldByKey(string $key): ?PluginSettingsField
    {
        foreach ($this->settingsFields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function secretByKey(string $key): ?PluginSecretField
    {
        foreach ($this->secretFields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->settingsFields as $field) {
            if (!$field->isWritable()) {
                continue;
            }

            $defaults[$field->key] = $field->default;
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function parseSettingsField(array $entry): ?PluginSettingsField
    {
        $key = trim((string) ($entry['key'] ?? ''));
        $type = trim((string) ($entry['type'] ?? ''));
        $label = trim((string) ($entry['label'] ?? ''));
        if ($key === '' || $label === '' || !in_array($type, PluginSettingsField::TYPES, true)) {
            return null;
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            return null;
        }

        $options = [];
        if (isset($entry['options']) && is_array($entry['options'])) {
            foreach ($entry['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $value = trim((string) ($option['value'] ?? ''));
                $optionLabel = trim((string) ($option['label'] ?? $value));
                if ($value === '') {
                    continue;
                }

                $options[] = ['value' => $value, 'label' => $optionLabel];
            }
        }

        if (in_array($type, [PluginSettingsField::TYPE_SELECT, PluginSettingsField::TYPE_MULTISELECT], true) && $options === []) {
            return null;
        }

        $default = $entry['default'] ?? self::defaultForType($type);
        $min = isset($entry['min']) ? (int) $entry['min'] : null;
        $max = isset($entry['max']) ? (int) $entry['max'] : null;
        $description = isset($entry['description']) ? trim((string) $entry['description']) : null;
        if ($description === '') {
            $description = null;
        }

        $secretKey = null;
        if ($type === PluginSettingsField::TYPE_SECRET_REF) {
            $secretKey = trim((string) ($entry['secret_key'] ?? $key));
            if ($secretKey === '') {
                return null;
            }
        }

        return new PluginSettingsField(
            key: $key,
            type: $type,
            label: $label,
            default: $default,
            options: $options,
            min: $min,
            max: $max,
            description: $description,
            secretKey: $secretKey,
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function parseSecretField(array $entry): ?PluginSecretField
    {
        $key = trim((string) ($entry['key'] ?? ''));
        $configPath = trim((string) ($entry['config_path'] ?? ''));
        $label = trim((string) ($entry['label'] ?? ''));
        if ($key === '' || $configPath === '' || $label === '') {
            return null;
        }

        $description = isset($entry['description']) ? trim((string) $entry['description']) : null;
        if ($description === '') {
            $description = null;
        }

        return new PluginSecretField(
            key: $key,
            configPath: $configPath,
            label: $label,
            description: $description,
        );
    }

    private static function defaultForType(string $type): mixed
    {
        return match ($type) {
            PluginSettingsField::TYPE_BOOLEAN => false,
            PluginSettingsField::TYPE_INTEGER => 0,
            PluginSettingsField::TYPE_MULTISELECT, PluginSettingsField::TYPE_STRING_LIST => [],
            default => '',
        };
    }
}