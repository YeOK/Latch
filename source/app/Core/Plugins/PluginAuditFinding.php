<?php

declare(strict_types=1);

namespace Latch\Core\Plugins;

/**
 * Single plugin-audit finding.
 */
final class PluginAuditFinding
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_INFO = 'info';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->file !== null) {
            $row['file'] = $this->file;
        }

        if ($this->line !== null) {
            $row['line'] = $this->line;
        }

        return $row;
    }
}