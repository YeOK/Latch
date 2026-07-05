<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\SettingRepository;

/**
 * Report reason categories and severity mapping (site-configurable).
 */
final class ReportReasons
{
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /** @var array<string, array{label: string, severity: string}> */
    private const DEFAULTS = [
        'spam' => ['label' => 'Spam or advertising', 'severity' => self::SEVERITY_LOW],
        'harassment' => ['label' => 'Harassment or bullying', 'severity' => self::SEVERITY_HIGH],
        'hate' => ['label' => 'Hate speech', 'severity' => self::SEVERITY_CRITICAL],
        'illegal' => ['label' => 'Illegal content', 'severity' => self::SEVERITY_CRITICAL],
        'off_topic' => ['label' => 'Off-topic', 'severity' => self::SEVERITY_LOW],
        'other' => ['label' => 'Other', 'severity' => self::SEVERITY_MEDIUM],
    ];

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    /**
     * @return array<string, array{label: string, severity: string}>
     */
    public function categories(): array
    {
        $raw = $this->settings->get('report_reasons');
        if ($raw === null || $raw === '') {
            return self::DEFAULTS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return self::DEFAULTS;
        }

        $normalized = [];
        foreach ($decoded as $code => $entry) {
            if (!is_string($code) || !is_array($entry)) {
                continue;
            }
            $label = (string) ($entry['label'] ?? $code);
            $severity = $this->normalizeSeverity((string) ($entry['severity'] ?? self::SEVERITY_MEDIUM));
            $normalized[$code] = ['label' => $label, 'severity' => $severity];
        }

        return $normalized !== [] ? $normalized : self::DEFAULTS;
    }

    public function label(string $code): string
    {
        return $this->categories()[$code]['label'] ?? $code;
    }

    /**
     * @return array<string, string> reason code => translation key
     */
    public static function labelKeys(): array
    {
        return [
            'spam' => 'report.spam',
            'harassment' => 'report.harassment',
            'hate' => 'report.hate',
            'illegal' => 'report.illegal',
            'off_topic' => 'report.off_topic',
            'other' => 'report.other',
        ];
    }

    /**
     * @param callable(string): string $translate
     * @return array<string, array{label: string, severity: string}>
     */
    public function translatedCategories(callable $translate): array
    {
        $categories = [];
        foreach ($this->categories() as $code => $entry) {
            $key = self::labelKeys()[$code] ?? null;
            $label = $key !== null ? $translate($key) : (string) $entry['label'];
            if ($key !== null && $label === $key) {
                $label = (string) $entry['label'];
            }
            $categories[$code] = [
                'label' => $label,
                'severity' => (string) $entry['severity'],
            ];
        }

        return $categories;
    }

    public function severityFor(string $code): string
    {
        return $this->categories()[$code]['severity'] ?? self::SEVERITY_MEDIUM;
    }

    public function isValidCode(string $code): bool
    {
        return isset($this->categories()[$code]);
    }

    public function quarantineMinSeverity(): string
    {
        $value = $this->settings->get('report_quarantine_min_severity', self::SEVERITY_HIGH);

        return $this->normalizeSeverity((string) $value);
    }

    public function quarantineReportCount(): int
    {
        $value = (int) $this->settings->get('report_quarantine_report_count', '0');

        return max(0, $value);
    }

    public function severityMeetsThreshold(string $severity, string $minimum): bool
    {
        return self::severityRank($severity) >= self::severityRank($minimum);
    }

    public static function severityRank(string $severity): int
    {
        return match (self::normalizeSeverity($severity)) {
            self::SEVERITY_CRITICAL => 4,
            self::SEVERITY_HIGH => 3,
            self::SEVERITY_MEDIUM => 2,
            default => 1,
        };
    }

    public static function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return match ($severity) {
            self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW => $severity,
            default => self::SEVERITY_MEDIUM,
        };
    }
}