<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditFinding;
use Latch\Core\Security\ThemeJsAuditor;
use PHPUnit\Framework\TestCase;

final class ThemeJsAuditorTest extends TestCase
{
    private string $root;
    private ThemeJsAuditor $auditor;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->auditor = new ThemeJsAuditor($this->root . '/themes/default/assets/js');
    }

    public function testDefaultThemeJsHasNoCriticalFindings(): void
    {
        $findings = $this->auditor->audit();

        $critical = array_values(array_filter(
            $findings,
            static fn (PluginAuditFinding $finding): bool => $finding->severity === PluginAuditFinding::SEVERITY_CRITICAL,
        ));

        $this->assertSame(
            [],
            $critical,
            $this->formatFindings($critical),
        );
    }

    public function testDetectsUnsanitizedUserIdInHref(): void
    {
        $dir = $this->makeTempJsDir();
        file_put_contents(
            $dir . '/bad.js',
            "link.href = '/admin/users/' + userId;\n",
        );

        $findings = (new ThemeJsAuditor($dir))->audit();

        $this->assertContains('js_xss_href_user_id', $this->findingCodes($findings));
        $this->assertGreaterThanOrEqual(1, (new ThemeJsAuditor($dir))->criticalCount($findings));
    }

    public function testNormalizedUserIdInHrefPasses(): void
    {
        $dir = $this->makeTempJsDir();
        file_put_contents(
            $dir . '/good.js',
            "var id = String(userId || '').replace(/[^0-9]/g, '');\n"
            . "link.href = '/admin/users/' + id;\n",
        );

        $findings = (new ThemeJsAuditor($dir))->audit();
        $critical = array_filter(
            $findings,
            static fn (PluginAuditFinding $finding): bool => $finding->severity === PluginAuditFinding::SEVERITY_CRITICAL,
        );

        $this->assertSame([], array_values($critical));
    }

    public function testInnerHtmlAssignmentWarns(): void
    {
        $dir = $this->makeTempJsDir();
        file_put_contents($dir . '/warn.js', "el.innerHTML = payload.html;\n");

        $findings = (new ThemeJsAuditor($dir))->audit();

        $this->assertContains('js_inner_html', $this->findingCodes($findings));
        $this->assertSame(0, (new ThemeJsAuditor($dir))->criticalCount($findings));
        $this->assertGreaterThan(0, (new ThemeJsAuditor($dir))->warnCount($findings));
    }

    /**
     * @param list<PluginAuditFinding> $findings
     * @return list<string>
     */
    private function findingCodes(array $findings): array
    {
        return array_map(static fn (PluginAuditFinding $finding): string => $finding->code, $findings);
    }

    /**
     * @param list<PluginAuditFinding> $findings
     */
    private function formatFindings(array $findings): string
    {
        if ($findings === []) {
            return '';
        }

        $lines = ['Theme JS critical findings:'];
        foreach ($findings as $finding) {
            $location = $finding->file ?? '';
            if ($finding->line !== null) {
                $location .= ':' . $finding->line;
            }

            $lines[] = sprintf('[%s] %s %s — %s', $finding->severity, $finding->code, $location, $finding->message);
        }

        return implode("\n", $lines);
    }

    private function makeTempJsDir(): string
    {
        $dir = sys_get_temp_dir() . '/latch-theme-js-audit-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}