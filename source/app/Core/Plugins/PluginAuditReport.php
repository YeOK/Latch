<?php

declare(strict_types=1);

namespace Latch\Core\Plugins;

/**
 * Aggregated plugin-audit result.
 */
final class PluginAuditReport
{
    /**
     * @param list<PluginAuditFinding> $findings
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $slug,
        public readonly array $findings,
    ) {
    }

    /** Prefixes for hook-injection warnings that block admin/CLI enable. */
    private const ENABLE_BLOCKING_WARN_PREFIXES = ['markup_', 'js_'];

    public function passed(): bool
    {
        return $this->criticalCount() === 0;
    }

    /**
     * Stricter than passed(): also blocks markup/JS injection warnings that could become sitewide XSS via hooks.
     */
    public function enableAllowed(): bool
    {
        if (!$this->passed()) {
            return false;
        }

        foreach ($this->findings as $finding) {
            if ($finding->severity !== PluginAuditFinding::SEVERITY_WARN) {
                continue;
            }

            foreach (self::ENABLE_BLOCKING_WARN_PREFIXES as $prefix) {
                if (str_starts_with($finding->code, $prefix)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function criticalCount(): int
    {
        return $this->countBySeverity(PluginAuditFinding::SEVERITY_CRITICAL);
    }

    public function warnCount(): int
    {
        return $this->countBySeverity(PluginAuditFinding::SEVERITY_WARN);
    }

    public function infoCount(): int
    {
        return $this->countBySeverity(PluginAuditFinding::SEVERITY_INFO);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'path' => $this->path,
            'passed' => $this->passed(),
            'summary' => [
                'critical' => $this->criticalCount(),
                'warn' => $this->warnCount(),
                'info' => $this->infoCount(),
            ],
            'findings' => array_map(static fn (PluginAuditFinding $f): array => $f->toArray(), $this->findings),
        ];
    }

    public function toHuman(): string
    {
        $label = $this->slug !== null ? "{$this->slug} ({$this->path})" : $this->path;
        $status = $this->passed() ? 'PASS' : 'FAIL';
        $lines = [
            "Plugin audit: {$label}",
            sprintf(
                'Status: %s (%d critical, %d warning, %d info)',
                $status,
                $this->criticalCount(),
                $this->warnCount(),
                $this->infoCount(),
            ),
        ];

        if ($this->findings === []) {
            $lines[] = 'No findings.';

            return implode("\n", $lines) . "\n";
        }

        $lines[] = '';
        foreach ($this->findings as $finding) {
            $prefix = strtoupper($finding->severity);
            $location = '';
            if ($finding->file !== null) {
                $location = $finding->line !== null
                    ? " {$finding->file}:{$finding->line}"
                    : " {$finding->file}";
            }

            $lines[] = "[{$prefix}] {$finding->code}{$location} — {$finding->message}";
        }

        return implode("\n", $lines) . "\n";
    }

    private function countBySeverity(string $severity): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->severity === $severity) {
                ++$count;
            }
        }

        return $count;
    }
}