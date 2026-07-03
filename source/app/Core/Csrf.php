<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * CSRF token generation and validation for forms.
 */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }

        return $token;
    }

    public function validate(?string $provided): bool
    {
        $expected = $this->session->get('_csrf');
        if (!is_string($expected) || $expected === '' || !is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}