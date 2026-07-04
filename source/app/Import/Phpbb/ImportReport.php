<?php

declare(strict_types=1);

namespace Latch\Import\Phpbb;

/**
 * Human and JSON import summary.
 */
final class ImportReport
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $counts = [
        'users' => 0,
        'boards' => 0,
        'topics' => 0,
        'posts' => 0,
        'skipped_users' => 0,
        'bbcode_warnings' => 0,
    ];

    public function setCount(string $key, int $value): void
    {
        $this->counts[$key] = $value;
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function count(string $key): int
    {
        return (int) ($this->counts[$key] ?? 0);
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'counts' => $this->counts,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function toHuman(): string
    {
        $lines = [];
        $lines[] = 'phpBB import report';
        $lines[] = str_repeat('-', 40);
        $lines[] = sprintf(
            'Users: %d | Boards: %d | Topics: %d | Posts: %d',
            $this->counts['users'],
            $this->counts['boards'],
            $this->counts['topics'],
            $this->counts['posts'],
        );
        if (($this->counts['skipped_users'] ?? 0) > 0) {
            $lines[] = 'Skipped users (bots/inactive): ' . $this->counts['skipped_users'];
        }
        if (($this->counts['bbcode_warnings'] ?? 0) > 0) {
            $lines[] = 'BBCode warnings: ' . $this->counts['bbcode_warnings'];
        }

        if ($this->warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach (array_slice($this->warnings, 0, 20) as $warning) {
                $lines[] = '  - ' . $warning;
            }
            if (count($this->warnings) > 20) {
                $lines[] = '  … and ' . (count($this->warnings) - 20) . ' more';
            }
        }

        if ($this->errors !== []) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ($this->errors as $error) {
                $lines[] = '  - ' . $error;
            }
        }

        $lines[] = '';
        $lines[] = $this->ok() ? 'Status: OK' : 'Status: FAILED';

        return implode("\n", $lines);
    }
}