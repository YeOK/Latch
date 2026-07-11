<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Validate admin POST input against manifest settings_schema.
 */
final class PluginSettingsValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, error: ?string}
     */
    public function validate(array $input, PluginSettingsSchema $schema): array
    {
        $values = [];

        foreach ($schema->settingsFields as $field) {
            if (!$field->isWritable()) {
                continue;
            }

            $raw = $input[$field->key] ?? null;
            $result = $this->validateField($field, $raw);
            if ($result['error'] !== null) {
                return ['values' => [], 'error' => $result['error']];
            }

            $values[$field->key] = $result['value'];
        }

        return ['values' => $values, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function validateField(PluginSettingsField $field, mixed $raw): array
    {
        return match ($field->type) {
            PluginSettingsField::TYPE_BOOLEAN => $this->validateBoolean($field, $raw),
            PluginSettingsField::TYPE_STRING => $this->validateString($field, $raw, false),
            PluginSettingsField::TYPE_TEXT => $this->validateString($field, $raw, true),
            PluginSettingsField::TYPE_INTEGER => $this->validateInteger($field, $raw),
            PluginSettingsField::TYPE_SELECT => $this->validateSelect($field, $raw),
            PluginSettingsField::TYPE_MULTISELECT => $this->validateMultiselect($field, $raw),
            PluginSettingsField::TYPE_STRING_LIST => $this->validateStringList($field, $raw),
            default => ['value' => $field->default, 'error' => null],
        };
    }

    /**
     * @return array{value: bool, error: ?string}
     */
    private function validateBoolean(PluginSettingsField $field, mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === false || $raw === '0') {
            return ['value' => false, 'error' => null];
        }

        return ['value' => true, 'error' => null];
    }

    /**
     * @return array{value: string, error: ?string}
     */
    private function validateString(PluginSettingsField $field, mixed $raw, bool $multiline): array
    {
        $value = is_string($raw) ? trim($raw) : '';
        if ($field->max !== null && mb_strlen($value, 'UTF-8') > $field->max) {
            return ['value' => '', 'error' => "{$field->label} must be at most {$field->max} characters."];
        }

        if (!$multiline && str_contains($value, "\n")) {
            return ['value' => '', 'error' => "{$field->label} cannot contain line breaks."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: int, error: ?string}
     */
    private function validateInteger(PluginSettingsField $field, mixed $raw): array
    {
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            return ['value' => 0, 'error' => "{$field->label} must be a number."];
        }

        $value = (int) $raw;
        if ($field->min !== null && $value < $field->min) {
            return ['value' => 0, 'error' => "{$field->label} must be at least {$field->min}."];
        }

        if ($field->max !== null && $value > $field->max) {
            return ['value' => 0, 'error' => "{$field->label} must be at most {$field->max}."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: string, error: ?string}
     */
    private function validateSelect(PluginSettingsField $field, mixed $raw): array
    {
        $value = is_string($raw) ? trim($raw) : '';
        if (!in_array($value, $field->optionValues(), true)) {
            return ['value' => '', 'error' => "{$field->label} has an invalid selection."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: list<string>, error: ?string}
     */
    private function validateMultiselect(PluginSettingsField $field, mixed $raw): array
    {
        $allowed = array_fill_keys($field->optionValues(), true);
        $selected = [];

        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_string($entry)) {
                    continue;
                }

                $entry = trim($entry);
                if ($entry === '' || !isset($allowed[$entry])) {
                    continue;
                }

                $selected[$entry] = true;
            }
        }

        return ['value' => array_keys($selected), 'error' => null];
    }

    /**
     * @return array{value: list<string>, error: ?string}
     */
    private function validateStringList(PluginSettingsField $field, mixed $raw): array
    {
        $lines = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry)) {
                    $lines[] = $entry;
                }
            }
        } elseif (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        $values = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $values[] = $line;
        }

        return ['value' => array_values(array_unique($values)), 'error' => null];
    }
}