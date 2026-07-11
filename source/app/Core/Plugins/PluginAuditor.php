<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;

/**
 * Static security scanner for third-party plugins (Phase 4b).
 */
final class PluginAuditor
{
    private const MAX_FILE_BYTES = 524288;

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const CRITICAL_PATTERNS = [
        ['pattern' => '/\beval\s*\(/i', 'code' => 'dangerous_eval', 'message' => 'eval() is not allowed in plugins'],
        ['pattern' => '/\bexec\s*\(/i', 'code' => 'dangerous_exec', 'message' => 'exec() is not allowed in plugins'],
        ['pattern' => '/\bshell_exec\s*\(/i', 'code' => 'dangerous_shell_exec', 'message' => 'shell_exec() is not allowed in plugins'],
        ['pattern' => '/\bsystem\s*\(/i', 'code' => 'dangerous_system', 'message' => 'system() is not allowed in plugins'],
        ['pattern' => '/\bpassthru\s*\(/i', 'code' => 'dangerous_passthru', 'message' => 'passthru() is not allowed in plugins'],
        ['pattern' => '/\bproc_open\s*\(/i', 'code' => 'dangerous_proc_open', 'message' => 'proc_open() is not allowed in plugins'],
        ['pattern' => '/\bpopen\s*\(/i', 'code' => 'dangerous_popen', 'message' => 'popen() is not allowed in plugins'],
        ['pattern' => '/\bcreate_function\s*\(/i', 'code' => 'dangerous_create_function', 'message' => 'create_function() is not allowed in plugins'],
        ['pattern' => '/\bassert\s*\(\s*[\'"`]/i', 'code' => 'dangerous_assert', 'message' => 'String assert() is not allowed in plugins'],
        ['pattern' => '/\b(include|require)(_once)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/i', 'code' => 'dynamic_include', 'message' => 'Dynamic include/require from request data is not allowed'],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const WARN_PATTERNS = [
        ['pattern' => '/\bbase64_decode\s*\(/i', 'code' => 'suspicious_base64_decode', 'message' => 'base64_decode() — review for obfuscation'],
        ['pattern' => '/\bgzinflate\s*\(/i', 'code' => 'suspicious_gzinflate', 'message' => 'gzinflate() — review for obfuscation'],
        ['pattern' => '/\bstr_rot13\s*\(/i', 'code' => 'suspicious_str_rot13', 'message' => 'str_rot13() — review for obfuscation'],
        ['pattern' => '/\$[a-zA-Z_][\w]*\s*\(/', 'code' => 'variable_function_call', 'message' => 'Variable function call — review for obfuscation'],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const NETWORK_PATTERNS = [
        ['pattern' => '/\bcurl_exec\s*\(/i', 'code' => 'network_curl', 'message' => 'Outbound network via curl_exec()'],
        ['pattern' => '/\bfile_get_contents\s*\(\s*[\'"`]https?:\/\//i', 'code' => 'network_file_get_contents', 'message' => 'Outbound HTTP(S) via file_get_contents()'],
        ['pattern' => '/\bfopen\s*\(\s*[\'"`]https?:\/\//i', 'code' => 'network_fopen', 'message' => 'Outbound HTTP(S) via fopen()'],
        ['pattern' => '/\bstream_socket_client\s*\(/i', 'code' => 'network_socket', 'message' => 'Outbound socket connection'],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const WRITE_PATTERNS = [
        ['pattern' => '/\bfile_put_contents\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem write via file_put_contents()'],
        ['pattern' => '/\bfwrite\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem write via fwrite()'],
        ['pattern' => '/\bunlink\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem delete via unlink()'],
        ['pattern' => '/\brename\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem rename'],
        ['pattern' => '/\bmkdir\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem mkdir'],
        ['pattern' => '/\bcopy\s*\(/i', 'code' => 'filesystem_write', 'message' => 'Filesystem copy'],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const MARKUP_WARN_PATTERNS = [
        ['pattern' => '/<script\b/i', 'code' => 'markup_script_tag', 'message' => '<script> tag in PHP source — review hook HTML injection'],
        ['pattern' => '/javascript\s*:/i', 'code' => 'markup_javascript_url', 'message' => 'javascript: URL scheme — review hook HTML'],
        ['pattern' => '/\bon[a-z][a-z0-9]*\s*=/i', 'code' => 'markup_inline_event_handler', 'message' => 'Inline event handler (onclick=, onerror=, …) in string'],
        ['pattern' => '/<iframe\b/i', 'code' => 'markup_iframe', 'message' => '<iframe> — review embedding'],
        ['pattern' => '/<object\b/i', 'code' => 'markup_object_tag', 'message' => '<object> — review embedding'],
        ['pattern' => '/<embed\b/i', 'code' => 'markup_embed_tag', 'message' => '<embed> — review embedding'],
        ['pattern' => '/\bsrcdoc\s*=/i', 'code' => 'markup_srcdoc', 'message' => 'srcdoc= attribute — inline HTML document'],
        ['pattern' => '/<meta\b[^>]*http-equiv\s*=\s*[\'"]?refresh/i', 'code' => 'markup_meta_refresh', 'message' => 'Meta refresh redirect'],
        ['pattern' => '/<base\b/i', 'code' => 'markup_base_tag', 'message' => '<base href> can hijack relative URLs'],
        ['pattern' => '/data\s*:\s*text\/html/i', 'code' => 'markup_data_html', 'message' => 'data:text/html URI'],
    ];

    /** @var list<array{pattern: string, code: string, message: string}> */
    private const JS_WARN_PATTERNS = [
        ['pattern' => '/\beval\s*\(/', 'code' => 'js_eval', 'message' => 'eval() in plugin JavaScript'],
        ['pattern' => '/\bnew\s+Function\s*\(/', 'code' => 'js_function_constructor', 'message' => 'new Function() dynamic code'],
        ['pattern' => '/\bdocument\.write\s*\(/', 'code' => 'js_document_write', 'message' => 'document.write()'],
        ['pattern' => '/\.innerHTML\s*=/', 'code' => 'js_inner_html', 'message' => 'innerHTML assignment'],
        ['pattern' => '/\.outerHTML\s*=/', 'code' => 'js_outer_html', 'message' => 'outerHTML assignment'],
        ['pattern' => '/\.insertAdjacentHTML\s*\(/', 'code' => 'js_insert_adjacent_html', 'message' => 'insertAdjacentHTML()'],
        ['pattern' => '/javascript\s*:/i', 'code' => 'js_javascript_url', 'message' => 'javascript: URL in JS string'],
        ['pattern' => '/\bon[a-z][a-z0-9]*\s*=/i', 'code' => 'js_inline_event_handler', 'message' => 'Inline handler in HTML string built from JS'],
        ['pattern' => '/\bdocument\.cookie\b/', 'code' => 'js_document_cookie', 'message' => 'document.cookie access'],
        ['pattern' => '/postMessage\s*\([^)]*[\'"]\*[\'"]/', 'code' => 'js_postmessage_wildcard', 'message' => "postMessage(…, '*') — overly permissive target"],
        ['pattern' => '/\bimport\s*\(\s*[\'"]https?:\/\//i', 'code' => 'js_dynamic_import_external', 'message' => 'Dynamic import() of external URL'],
        ['pattern' => '/(?:createElement|\.innerHTML)\s*[^(]*\([^)]*<script/i', 'code' => 'js_external_script_injection', 'message' => 'Script tag injection via DOM APIs (coarse)'],
        ['pattern' => '/\bfetch\s*\(\s*[\'"]https?:\/\//', 'code' => 'js_fetch_external', 'message' => 'fetch() to absolute external URL'],
        ['pattern' => '/\.open\s*\(\s*[\'"][A-Z]+[\'"]\s*,\s*[\'"]https?:\/\//', 'code' => 'js_xhr_external', 'message' => 'XHR to absolute external URL'],
    ];

    /** @var list<string> */
    private const FORBIDDEN_WRITE_TARGETS = [
        'config/local.php',
        'storage/database',
        '/database/latch.sqlite',
        'latch.sqlite',
    ];

    public function __construct(
        private readonly string $latchRoot,
        private readonly string $pluginsPath,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Resolve a slug, relative path, or absolute path to a plugin directory.
     */
    public function resolvePath(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            throw new InvalidArgumentException('Plugin path or slug is required');
        }

        if (is_dir($target) && is_file($target . '/plugin.json')) {
            return realpath($target) ?: $target;
        }

        $relative = $target;
        if (!str_starts_with($relative, '/')) {
            $candidates = [
                $this->pluginsPath . '/' . $relative,
                $this->latchRoot . '/' . ltrim($relative, '/'),
            ];

            foreach ($candidates as $candidate) {
                if (is_dir($candidate) && is_file($candidate . '/plugin.json')) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        throw new InvalidArgumentException("Plugin directory not found: {$target}");
    }

    public function auditPath(string $pluginDir): PluginAuditReport
    {
        $pluginDir = realpath($pluginDir) ?: $pluginDir;
        if (!is_dir($pluginDir) || !is_file($pluginDir . '/plugin.json')) {
            throw new InvalidArgumentException("Not a plugin directory: {$pluginDir}");
        }

        $findings = [];
        $slug = basename($pluginDir);
        $manifest = null;

        try {
            $manifest = PluginManifest::fromDirectory($pluginDir);
            $slug = $manifest->slug;
            $findings = array_merge($findings, $this->validateManifest($manifest));
        } catch (\Throwable $e) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_invalid',
                $e->getMessage(),
                'plugin.json',
            );
        }

        $findings = array_merge($findings, $this->scanTree($pluginDir, $manifest));

        if ($manifest !== null) {
            $findings = array_merge($findings, $this->validatePsr4Autoload($pluginDir, $manifest));
            if ($this->isInstalledPluginPath($pluginDir)) {
                $findings = array_merge($findings, $this->validateRuntimeEnvironment($manifest->slug));
            }
        }

        return new PluginAuditReport($pluginDir, $slug, $findings);
    }

    public function auditTarget(string $target): PluginAuditReport
    {
        return $this->auditPath($this->resolvePath($target));
    }

    /**
     * @return list<PluginAuditFinding>
     */
    private function validateManifest(PluginManifest $manifest): array
    {
        $findings = [];

        if (!preg_match('/^\d+\.\d+\.\d+(?:-[\w.]+)?$/', $manifest->version)) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_version',
                'version must be valid semver (e.g. 1.0.0)',
                'plugin.json',
            );
        }

        if (!preg_match('/^\d+\.\d+\.\d+(?:-[\w.]+)?$/', $manifest->minLatchVersion)) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_min_latch_version',
                'min_latch_version must be valid semver',
                'plugin.json',
            );
        }

        $declaredHooks = array_fill_keys($manifest->hooks, true);
        $known = array_fill_keys(HookName::all(), true);
        foreach ($manifest->hooks as $hook) {
            if (!isset($known[$hook])) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'manifest_unknown_hook',
                    "Unknown hook declared: {$hook}",
                    'plugin.json',
                );
            }
        }

        if ($declaredHooks === []) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_hooks',
                'At least one hook must be declared',
                'plugin.json',
            );
        }

        $findings = array_merge($findings, $this->validateCacheConfig($manifest));

        return $findings;
    }

    /**
     * @return list<PluginAuditFinding>
     */
    private function validateCacheConfig(PluginManifest $manifest): array
    {
        $findings = [];
        $cache = $manifest->cacheConfig;

        if ($cache->isFragment()) {
            if ($cache->fragmentHook === null) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'manifest_cache_fragment',
                    'cache.fragment is required when guest_page is fragment',
                    'plugin.json',
                );
            } elseif (!in_array($cache->fragmentHook, $manifest->hooks, true)) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'manifest_cache_fragment_hook',
                    'cache.fragment must be declared in hooks',
                    'plugin.json',
                );
            }
        }

        if ($cache->isClient() && ($cache->clientRoute === null || $cache->clientRoute === '')) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_cache_client',
                'cache.client is required when guest_page is client',
                'plugin.json',
            );
        } elseif (
            $cache->isClient()
            && $cache->clientRoute !== null
            && !preg_match('#^/[a-zA-Z0-9/_\-.]+(\?[a-zA-Z0-9&=%_\-.]+)?$#', $cache->clientRoute)
        ) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_CRITICAL,
                'manifest_cache_client_route',
                'cache.client must be a same-origin path (e.g. /plugin/foo/widget.json)',
                'plugin.json',
            );
        }

        return $findings;
    }

    /**
     * @return list<PluginAuditFinding>
     */
    private function scanTree(string $pluginDir, ?PluginManifest $manifest): array
    {
        $findings = [];
        $hasVendor = is_dir($pluginDir . '/vendor');
        $hasLock = is_file($pluginDir . '/composer.lock');

        if ($hasVendor && !$hasLock) {
            $findings[] = new PluginAuditFinding(
                PluginAuditFinding::SEVERITY_WARN,
                'vendor_without_lock',
                'Bundled vendor/ without composer.lock — review dependencies',
                'vendor/',
            );
        }

        $networkDeclared = $manifest !== null && $this->networkDeclared($manifest);
        $allowedWriteRoots = $this->allowedWriteRoots($pluginDir, $manifest);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $relative = ltrim(str_replace($pluginDir, '', $absolute), '/\\');

            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $size = $fileInfo->getSize();
            if ($size > self::MAX_FILE_BYTES) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_WARN,
                    'large_file',
                    sprintf('File exceeds %d KB (%d bytes)', self::MAX_FILE_BYTES / 1024, $size),
                    $relative,
                );
            }

            $lower = strtolower($relative);
            $isPhp = str_ends_with($lower, '.php');
            $isJs = str_ends_with($lower, '.js') || str_ends_with($lower, '.mjs');

            if (!$isPhp && !$isJs) {
                continue;
            }

            $contents = file_get_contents($absolute);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $lines = preg_split('/\R/', $contents) ?: [];
            foreach ($lines as $lineNumber => $line) {
                $lineNo = $lineNumber + 1;

                if ($isPhp) {
                    $findings = array_merge(
                        $findings,
                        $this->scanLineForPatterns($line, $lineNo, $relative, self::CRITICAL_PATTERNS, PluginAuditFinding::SEVERITY_CRITICAL),
                        $this->scanLineForPatterns($line, $lineNo, $relative, self::WARN_PATTERNS, PluginAuditFinding::SEVERITY_WARN),
                        $this->scanLineForPatterns($line, $lineNo, $relative, self::MARKUP_WARN_PATTERNS, PluginAuditFinding::SEVERITY_WARN),
                    );

                    if (!$networkDeclared) {
                        $findings = array_merge(
                            $findings,
                            $this->scanLineForPatterns(
                                $line,
                                $lineNo,
                                $relative,
                                self::NETWORK_PATTERNS,
                                PluginAuditFinding::SEVERITY_CRITICAL,
                                static fn (string $message): string => $message . ' — declare permissions.network in plugin.json',
                            ),
                        );
                    }

                    foreach (self::WRITE_PATTERNS as $rule) {
                        if (!preg_match($rule['pattern'], $line)) {
                            continue;
                        }

                        $findings = array_merge(
                            $findings,
                            $this->checkWriteLine($line, $relative, $lineNo, $allowedWriteRoots),
                        );
                    }

                    if (preg_match('/\.\./', $line) && preg_match('/\b(file_put_contents|fopen|unlink|rename|copy|mkdir)\s*\(/i', $line)) {
                        $findings[] = new PluginAuditFinding(
                            PluginAuditFinding::SEVERITY_CRITICAL,
                            'path_traversal',
                            'Path traversal (..) near filesystem operation',
                            $relative,
                            $lineNo,
                        );
                    }
                }

                if ($isJs) {
                    $findings = array_merge(
                        $findings,
                        $this->scanLineForPatterns($line, $lineNo, $relative, self::JS_WARN_PATTERNS, PluginAuditFinding::SEVERITY_WARN),
                    );
                }
            }
        }

        return $this->dedupeFindings($findings);
    }

    /**
     * @param list<array{pattern: string, code: string, message: string}> $rules
     * @param callable(string): string|null $messageTransform
     * @return list<PluginAuditFinding>
     */
    private function scanLineForPatterns(
        string $line,
        int $lineNo,
        string $relative,
        array $rules,
        string $severity,
        ?callable $messageTransform = null,
    ): array {
        $findings = [];

        foreach ($rules as $rule) {
            if (!preg_match($rule['pattern'], $line)) {
                continue;
            }

            $message = $rule['message'];
            if ($messageTransform !== null) {
                $message = $messageTransform($message);
            }

            $findings[] = new PluginAuditFinding(
                $severity,
                $rule['code'],
                $message,
                $relative,
                $lineNo,
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $allowedWriteRoots
     * @return list<PluginAuditFinding>
     */
    private function checkWriteLine(string $line, string $relative, int $lineNo, array $allowedWriteRoots): array
    {
        $findings = [];

        foreach (self::FORBIDDEN_WRITE_TARGETS as $forbidden) {
            if (stripos($line, $forbidden) !== false) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'forbidden_write_target',
                    "Write target references forbidden path: {$forbidden}",
                    $relative,
                    $lineNo,
                );
            }
        }

        if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
            foreach ($matches[1] as $literal) {
                if ($this->isAllowedWriteLiteral($literal, $allowedWriteRoots)) {
                    continue;
                }

                if ($this->looksLikeAbsoluteOrSensitivePath($literal)) {
                    $findings[] = new PluginAuditFinding(
                        PluginAuditFinding::SEVERITY_CRITICAL,
                        'undeclared_write_path',
                        "Filesystem write to undeclared path: {$literal}",
                        $relative,
                        $lineNo,
                    );
                }
            }
        }

        return $findings;
    }

    private function networkDeclared(PluginManifest $manifest): bool
    {
        $network = $manifest->permissions['network'] ?? null;
        if ($network === true) {
            return true;
        }

        return is_array($network) && $network !== [];
    }

    /**
     * @return list<string>
     */
    private function allowedWriteRoots(string $pluginDir, ?PluginManifest $manifest): array
    {
        $roots = [rtrim($pluginDir, '/')];

        $slug = $manifest?->slug ?? basename($pluginDir);
        $roots[] = rtrim($this->storagePath, '/') . '/plugins/' . $slug;

        $filesystem = $manifest?->permissions['filesystem'] ?? [];
        if (is_array($filesystem)) {
            foreach ($filesystem as $entry) {
                if (!is_string($entry) || trim($entry) === '') {
                    continue;
                }

                $entry = trim($entry);
                if (str_starts_with($entry, '/')) {
                    $roots[] = $entry;
                    continue;
                }

                if (str_starts_with($entry, 'storage/')) {
                    $roots[] = rtrim($this->latchRoot, '/') . '/' . $entry;
                    continue;
                }

                $roots[] = rtrim($pluginDir, '/') . '/' . ltrim($entry, '/');
            }
        }

        return array_values(array_unique($roots));
    }

    private function isAllowedWriteLiteral(string $literal, array $allowedWriteRoots): bool
    {
        if ($literal === '' || str_contains($literal, '$') || str_contains($literal, '{')) {
            return true;
        }

        $normalized = str_replace('\\', '/', $literal);
        foreach ($allowedWriteRoots as $root) {
            $rootNorm = str_replace('\\', '/', $root);
            if (str_starts_with($normalized, $rootNorm) || str_starts_with($normalized, basename($rootNorm))) {
                return true;
            }
        }

        return !$this->looksLikeAbsoluteOrSensitivePath($normalized);
    }

    private function looksLikeAbsoluteOrSensitivePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/')) {
            return true;
        }

        if (preg_match('#^(config|storage)/#i', $path)) {
            return true;
        }

        foreach (self::FORBIDDEN_WRITE_TARGETS as $forbidden) {
            if (stripos($path, $forbidden) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PluginAuditFinding>
     */
    private function validatePsr4Autoload(string $pluginDir, PluginManifest $manifest): array
    {
        $findings = [];
        $srcDir = $pluginDir . '/src/';
        if (!is_dir($srcDir)) {
            return $findings;
        }

        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug($manifest->slug) . '\\';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || !str_ends_with(strtolower($fileInfo->getFilename()), '.php')) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $relative = 'src/' . ltrim(str_replace('\\', '/', substr($absolute, strlen($srcDir))), '/');

            foreach ($this->extractDeclaredTypes($absolute) as $fqcn) {
                if (!str_starts_with($fqcn, $prefix)) {
                    continue;
                }

                $relativeClass = substr($fqcn, strlen($prefix));
                $expectedRelative = 'src/' . str_replace('\\', '/', $relativeClass) . '.php';
                if ($relative !== $expectedRelative) {
                    $findings[] = new PluginAuditFinding(
                        PluginAuditFinding::SEVERITY_CRITICAL,
                        'psr4_autoload_mismatch',
                        "Class {$fqcn} must live in {$expectedRelative} for PluginLoader autoload (declared in {$relative})",
                        $relative,
                    );
                }
            }
        }

        return $findings;
    }

    private function isInstalledPluginPath(string $pluginDir): bool
    {
        $pluginsRoot = realpath($this->pluginsPath) ?: rtrim($this->pluginsPath, '/');
        $pluginReal = realpath($pluginDir) ?: $pluginDir;

        return $pluginReal === $pluginsRoot || str_starts_with($pluginReal, $pluginsRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<PluginAuditFinding>
     */
    private function validateRuntimeEnvironment(string $slug): array
    {
        $findings = [];
        $webUser = PluginStoragePermissions::webUser();
        $storageRoot = rtrim($this->storagePath, '/');

        $pluginStorage = $storageRoot . '/plugins/' . $slug;
        if (is_dir($pluginStorage)) {
            $owner = $this->pathOwnerName($pluginStorage);
            if ($owner === 'root') {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'runtime_storage_root_owned',
                    "storage/plugins/{$slug}/ is owned by root — settings and migrations may fail; run: sudo chown -R {$webUser}:{$webUser} {$pluginStorage}",
                    "storage/plugins/{$slug}/",
                );
            } elseif (!$this->isWritableByWebUser($pluginStorage)) {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'runtime_storage_not_writable',
                    "storage/plugins/{$slug}/ is not writable by {$webUser} — plugin settings cannot be saved",
                    "storage/plugins/{$slug}/",
                );
            }
        }

        $auditCacheDir = $storageRoot . '/cache/plugin-audits';
        if (is_dir($auditCacheDir)) {
            $owner = $this->pathOwnerName($auditCacheDir);
            if ($owner === 'root') {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'runtime_audit_cache_root_owned',
                    'storage/cache/plugin-audits/ is owned by root — audit cache writes fail; run plugin commands as: sudo latch …',
                    'storage/cache/plugin-audits/',
                );
            }

            $auditCacheFile = $auditCacheDir . '/' . $slug . '.json';
            if (is_file($auditCacheFile) && $this->pathOwnerName($auditCacheFile) === 'root') {
                $findings[] = new PluginAuditFinding(
                    PluginAuditFinding::SEVERITY_CRITICAL,
                    'runtime_audit_cache_file_root_owned',
                    "Audit cache file for {$slug} is owned by root — re-run: sudo latch plugin audit {$slug}",
                    'storage/cache/plugin-audits/' . $slug . '.json',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function extractDeclaredTypes(string $filePath): array
    {
        $source = file_get_contents($filePath);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $types = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readQualifiedNameFromTokens($tokens, $i + 1);
                continue;
            }

            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            if ($token[0] === T_CLASS && $this->previousMeaningfulTokenType($tokens, $i - 1) === T_NEW) {
                continue;
            }

            $name = $this->readQualifiedNameFromTokens($tokens, $i + 1);
            if ($name === '') {
                continue;
            }

            $types[] = $namespace === '' ? $name : $namespace . '\\' . $name;
        }

        return $types;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function readQualifiedNameFromTokens(array $tokens, int $start): string
    {
        $parts = [];
        $count = count($tokens);

        for ($i = $start; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_NAME_QUALIFIED) {
                $parts[] = $token[1];

                continue;
            }

            if (is_array($token) && $token[0] === T_NS_SEPARATOR) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                $parts[] = $token[1];

                continue;
            }

            break;
        }

        return implode('\\', $parts);
    }

    /**
     * @param list<mixed> $tokens
     */
    private function previousMeaningfulTokenType(array $tokens, int $index): ?int
    {
        for ($i = $index; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }

    private function pathOwnerName(string $path): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }

        $owner = fileowner($path);
        if (!is_int($owner)) {
            return null;
        }

        $passwd = posix_getpwuid($owner);

        return is_array($passwd) ? (string) ($passwd['name'] ?? '') : null;
    }

    private function isWritableByWebUser(string $path): bool
    {
        if (!function_exists('posix_getpwnam')) {
            return is_writable($path);
        }

        $passwd = posix_getpwnam(PluginStoragePermissions::webUser());
        if ($passwd === false) {
            return is_writable($path);
        }

        $uid = (int) $passwd['uid'];
        $gid = (int) $passwd['gid'];
        $owner = fileowner($path);
        $group = filegroup($path);
        $mode = fileperms($path) & 0777;

        if (is_int($owner) && $owner === $uid) {
            return ($mode & 0200) !== 0;
        }

        if (is_int($group) && $group === $gid) {
            return ($mode & 0020) !== 0;
        }

        return ($mode & 0002) !== 0;
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