<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Config;

/**
 * Build generic admin form rows from settings_schema.
 */
final class PluginSettingsForm
{
    /**
     * @param array<string, mixed> $values
     * @return array{settings_fields: list<array<string, mixed>>, secret_fields: list<array<string, mixed>>}
     */
    public function build(PluginSettingsSchema $schema, array $values, Config $config): array
    {
        $settingsFields = [];
        foreach ($schema->settingsFields as $field) {
            if ($field->type === PluginSettingsField::TYPE_SECRET_REF) {
                continue;
            }

            $settingsFields[] = $this->settingsRow($field, $values[$field->key] ?? $field->default);
        }

        $secretFields = [];
        foreach ($schema->secretFields as $secret) {
            $secretFields[] = [
                'key' => $secret->key,
                'label' => $secret->label,
                'description' => $secret->description,
                'config_path' => $secret->configPath,
                'configured' => $this->secretConfigured($config, $secret->configPath),
            ];
        }

        foreach ($schema->settingsFields as $field) {
            if ($field->type !== PluginSettingsField::TYPE_SECRET_REF) {
                continue;
            }

            $secret = $field->secretKey !== null ? $schema->secretByKey($field->secretKey) : null;
            $configPath = $secret?->configPath ?? '';
            $secretFields[] = [
                'key' => $field->key,
                'label' => $field->label,
                'description' => $field->description ?? $secret?->description,
                'config_path' => $configPath,
                'configured' => $configPath !== '' && $this->secretConfigured($config, $configPath),
            ];
        }

        return [
            'settings_fields' => $settingsFields,
            'secret_fields' => $secretFields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsRow(PluginSettingsField $field, mixed $value): array
    {
        return [
            'key' => $field->key,
            'type' => $field->type,
            'label' => $field->label,
            'description' => $field->description,
            'value' => $this->displayValue($field, $value),
            'checked' => (bool) $value,
            'options' => $field->options,
            'min' => $field->min,
            'max' => $field->max,
            'selected' => $this->selectedValues($field, $value),
        ];
    }

    private function displayValue(PluginSettingsField $field, mixed $value): mixed
    {
        if ($field->type === PluginSettingsField::TYPE_STRING_LIST && is_array($value)) {
            return implode("\n", array_map(static fn ($entry): string => (string) $entry, $value));
        }

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private function selectedValues(PluginSettingsField $field, mixed $value): array
    {
        if ($field->type !== PluginSettingsField::TYPE_MULTISELECT || !is_array($value)) {
            return [];
        }

        $selected = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $selected[$entry] = true;
            }
        }

        return $selected;
    }

    private function secretConfigured(Config $config, string $configPath): bool
    {
        $value = $config->get($configPath);
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    return true;
                }
            }
        }

        return $value !== null && $value !== false && $value !== '';
    }
}