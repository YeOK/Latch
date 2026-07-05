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
 * Outbound mail — msmtp (recommended) or PHP mail().
 *
 * Transport and from-address are configurable in Admin → Settings and config/local.php.
 */
final class Mail
{
    public const TRANSPORT_MSMTP = 'msmtp';
    public const TRANSPORT_MAIL = 'mail';

    private ?string $lastError = null;

    public function __construct(
        private readonly Config $config,
        private readonly SettingRepository $settings,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $this->lastError = null;

        if (!$this->isEnabled()) {
            $this->lastError = 'Outbound mail is disabled in settings.';

            return false;
        }

        $fromEmail = $this->fromEmail();
        $fromName = $this->fromName();

        $message = implode("\r\n", [
            'From: ' . sprintf('%s <%s>', $fromName, $fromEmail),
            'To: ' . $to,
            'Subject: ' . $subject,
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
        ]);

        $ok = match ($this->transport()) {
            self::TRANSPORT_MSMTP => $this->sendViaMsmtp($message),
            default => $this->sendViaPhpMail($to, $subject, $body, $fromEmail, $fromName),
        };

        if (!$ok) {
            $this->logFailure($to, $subject, $this->lastError ?? 'send failed');
        }

        return $ok;
    }

    public function isEnabled(): bool
    {
        if ($this->settings->get('mail_enabled') !== null) {
            return $this->settings->getBool('mail_enabled');
        }

        return (bool) $this->config->get('mail.enabled', true);
    }

    public function transport(): string
    {
        $fromSettings = $this->settings->get('mail_transport');
        if ($fromSettings !== null && $fromSettings !== '') {
            return $this->normalizeTransport((string) $fromSettings);
        }

        $fromConfig = (string) $this->config->get('mail.transport', self::TRANSPORT_MSMTP);

        return $this->normalizeTransport($fromConfig);
    }

    public function fromEmail(): string
    {
        $value = $this->settings->get('mail_from_email');
        if ($value !== null && $value !== '') {
            return $value;
        }

        return (string) $this->config->get('mail.from_email', 'noreply@localhost');
    }

    public function fromName(): string
    {
        $value = $this->settings->get('mail_from_name');
        if ($value !== null && $value !== '') {
            return $value;
        }

        return (string) $this->config->get('mail.from_name', 'Latch');
    }

    public function msmtpConfigPath(): ?string
    {
        $candidates = [];

        $configured = trim((string) $this->settings->get('mail_msmtp_config', ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $configPath = trim((string) $this->config->get('mail.msmtp_config', ''));
        if ($configPath !== '') {
            $candidates[] = $configPath;
        }

        $candidates[] = dirname(LATCH_ROOT) . '/deploy/msmtp.conf';

        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function isConfigured(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->transport() === self::TRANSPORT_MSMTP) {
            return $this->msmtpConfigPath() !== null;
        }

        return function_exists('mail');
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function siteUrl(): string
    {
        return rtrim((string) $this->config->get('site.url', 'http://localhost'), '/');
    }

    /**
     * @return array{enabled: bool, transport: string, from_email: string, from_name: string, msmtp_config: string, configured: bool}
     */
    public function status(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'transport' => $this->transport(),
            'from_email' => $this->fromEmail(),
            'from_name' => $this->fromName(),
            'msmtp_config' => (string) ($this->msmtpConfigPath() ?? ''),
            'configured' => $this->isConfigured(),
        ];
    }

    private function normalizeTransport(string $transport): string
    {
        $transport = strtolower(trim($transport));

        return match ($transport) {
            self::TRANSPORT_MAIL, 'php', 'sendmail' => self::TRANSPORT_MAIL,
            default => self::TRANSPORT_MSMTP,
        };
    }

    private function sendViaPhpMail(string $to, string $subject, string $body, string $fromEmail, string $fromName): bool
    {
        $headers = [
            'From: ' . sprintf('%s <%s>', $fromName, $fromEmail),
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        if (!$ok) {
            $this->lastError = 'PHP mail() returned false.';
        }

        return $ok;
    }

    private function sendViaMsmtp(string $message): bool
    {
        $config = $this->msmtpConfigPath();
        if ($config === null) {
            $this->lastError = 'msmtp config file not found or not readable.';

            return false;
        }

        if (!is_executable('/usr/bin/msmtp') && !is_executable('/usr/local/bin/msmtp')) {
            $this->lastError = 'msmtp binary not found.';

            return false;
        }

        $binary = is_executable('/usr/bin/msmtp') ? '/usr/bin/msmtp' : '/usr/local/bin/msmtp';
        $command = [$binary, '-C', $config, '-t', '-i'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->lastError = 'Could not start msmtp process.';

            return false;
        }

        fwrite($pipes[0], $message);
        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->lastError = trim($stderr !== '' ? $stderr : "msmtp exited with code {$exitCode}");

            return false;
        }

        return true;
    }

    private function logFailure(string $to, string $subject, string $reason): void
    {
        $logPath = (string) $this->config->get('paths.storage') . '/logs/mail.log';
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'to' => $to,
            'subject' => $subject,
            'error' => $reason,
            'transport' => $this->transport(),
        ], JSON_UNESCAPED_SLASHES) . "\n";

        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}