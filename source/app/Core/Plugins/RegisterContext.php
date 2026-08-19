<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Context for user.before_register (form or OIDC new-account).
 *
 * Identity fields are read-only. Abort with reject() — do not throw.
 * After reject(), later listeners are skipped. Side effects (one-time tokens)
 * should register onAbort() so AuthController can undo them if create() fails.
 *
 * $reason is shown to the registrant (Twig-escaped) and, on OIDC, logged.
 * Keep it generic: no email or username.
 */
final class RegisterContext implements StoppableHookContext
{
    public ?string $rejectReason = null;

    /** @var list<callable(): void> */
    private array $abortHandlers = [];

    /**
     * @param string $source `form` or `oidc`. OIDC passes username '' and inviteCode ''.
     */
    public function __construct(
        public readonly string $username,
        public readonly string $email,
        public readonly string $source,
        public readonly string $inviteCode = '',
    ) {
    }

    /**
     * Abort registration. $reason is flash-safe plain text (no PII, no HTML).
     */
    public function reject(string $reason): void
    {
        $this->rejectReason = $reason;
    }

    public function isPropagationStopped(): bool
    {
        return $this->rejectReason !== null;
    }

    /**
     * Run if users()->create() fails after this hook consumed a one-shot token.
     *
     * @param callable(): void $handler
     */
    public function onAbort(callable $handler): void
    {
        $this->abortHandlers[] = $handler;
    }

    public function runAbortHandlers(): void
    {
        foreach ($this->abortHandlers as $handler) {
            try {
                $handler();
            } catch (\Throwable) {
                // Best effort — create already failed.
            }
        }

        $this->abortHandlers = [];
    }
}
