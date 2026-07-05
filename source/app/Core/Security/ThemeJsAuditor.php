<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Security;

use Latch\Core\Plugins\PluginAuditFinding;

/**
 * Static scanner for first-party theme JavaScript (complements GitHub CodeQL).
 */
final class ThemeJsAuditor
{
    private const MAX_FILE_BYTES = 524288;

    /** @var list<string> */
    private const SKIP_FILES = [
        'highlight.min.js',
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const CRITICAL_PATTERNS = [
        ['pattern' => '/\beval\s*\(/', 'code' => 'js_eval', 'message' => 'eval() in theme JavaScript'],
        ['pattern' => '/\bnew\s+Function\s*\(/', 'code' => 'js_function_constructor', 'message' => 'new Function() dynamic code'],
        ['pattern' => '/\bdocument\.write\s*\(/', 'code' => 'js_document_write', 'message' => 'document.write()'],
        [
            'pattern' => '/\.href\s*=\s*[^;]*\+\s*(userId|user_id)\b/',
            'code' => 'js_xss_href_user_id',
            'message' => 'href built from userId/user_id without normalization — use numeric sanitization first',
        ],
        [
            'pattern' => '/\.innerHTML\s*=\s*[^;]*\b(userId|user_id|username)\b/',
            'code' => 'js_xss_innerhtml_user_field',
            'message' => 'innerHTML assignment references user-controlled field',
        ],
        [
            'pattern' => '/\.insertAdjacentHTML\s*\([^)]*\+\s*(userId|user_id|username)\b/',
            'code' => 'js_xss_insert_adjacent_user_field',
            'message' => 'insertAdjacentHTML() with user-controlled concatenation',
        ],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const WARN_PATTERNS = [
        ['pattern' => '/\.innerHTML\s*=/', 'code' => 'js_inner_html', 'message' => 'innerHTML assignment — prefer textContent/DOM APIs or server-escaped HTML'],
        ['pattern' => '/\.outerHTML\s*=/', 'code' => 'js_outer_html', 'message' => 'outerHTML assignment'],
        ['pattern' => '/\.insertAdjacentHTML\s*\(/', 'code' => 'js_insert_adjacent_html', 'message' => 'insertAdjacentHTML()'],
        ['pattern' => '/javascript\s*:/i', 'code' => 'js_javascript_url', 'message' => 'javascript: URL in JS string'],
        ['pattern' => '/\bon[a-z][a-z0-9]*\s*=/i', 'code' => 'js_inline_event_handler', 'message' => 'Inline handler in HTML string built from JS'],
    ];

    public function __construct(
        private readonly string $themeJsPath,
    ) {
    }

    /**
     * @return list<PluginAuditFinding>
     */
    public function audit(): array
    {
        if (!is_dir($this->themeJsPath)) {
            return [
                new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'theme_js_missing',
                    'Theme JavaScript directory not found',
                    $this->themeJsPath,
                ),
            ];
        }

        $findings = [];

        foreach (scandir($this->themeJsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (in_array($entry, self::SKIP_FILES, true)) {
                continue;
            }

            $absolute = $this->themeJsPath . '/' . $entry;
            if (!is_file($absolute)) {
                continue;
            }

            $lower = strtolower($entry);
            if (!str_ends_with($lower, '.js') && !str_ends_with($lower, '.mjs')) {
                continue;
            }

            $size = filesize($absolute);
            if (is_int($size) && $size > self::MAX_FILE_BYTES) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_WARN,
                    'large_file',
                    sprintf('File exceeds %d KB (%d bytes)', self::MAX_FILE_BYTES / 1024, $size),
                    $entry,
                );
            }

            $contents = file_get_contents($absolute);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $lines = preg_split('/\R/', $contents) ?: [];
            foreach ($lines as $lineNumber => $line) {
                $lineNo = $lineNumber + 1;
                $findings = array_merge(
                    $findings,
                    $this->scanLine($line, $lineNo, $entry, self::CRITICAL_PATTERNS, PluginAuditFinding::SEVERITY_CRITICAL),
                    $this->scanLine($line, $lineNo, $entry, self::WARN_PATTERNS, PluginAuditFinding::SEVERITY_WARN),
                );
            }
        }

        return $this->dedupeFindings($findings);
    }

    /**
     * @param list<PluginAuditFinding> $findings
     */
    public function criticalCount(array $findings): int
    {
        return $this->countBySeverity($findings, PluginAuditFinding::SEVERITY_CRITICAL);
    }

    /**
     * @param list<PluginAuditFinding> $findings
     */
    public function warnCount(array $findings): int
    {
        return $this->countBySeverity($findings, PluginAuditFinding::SEVERITY_WARN);
    }

    /**
     * @param list<array{pattern: string, code: string, message: string}> $rules
     * @return list<PluginAuditFinding>
     */
    private function scanLine(string $line, int $lineNo, string $file, array $rules, string $severity): array
    {
        $findings = [];

        foreach ($rules as $rule) {
            if (!preg_match($rule['pattern'], $line)) {
                continue;
            }

            $findings[] = new PluginAuditFinding(
                $severity,
                $rule['code'],
                $rule['message'],
                $file,
                $lineNo,
            );
        }

        return $findings;
    }

    /**
     * @param list<PluginAuditFinding> $findings
     */
    private function countBySeverity(array $findings, string $severity): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if ($finding->severity === $severity) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<PluginAuditFinding> $findings
     * @return list<PluginAuditFinding>
     */
    private function dedupeFindings(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding->severity,
                $finding->code,
                $finding->message,
                $finding->file ?? '',
                (string) ($finding->line ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }
}