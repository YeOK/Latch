<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

interface OutboundMailer
{
    public function send(string $to, string $subject, string $body): bool;

    public function isEnabled(): bool;

    public function isConfigured(): bool;

    public function lastError(): ?string;

    public function siteUrl(): string;
}