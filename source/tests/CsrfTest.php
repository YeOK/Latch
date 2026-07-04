<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Csrf;
use Latch\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private Session $session;
    private Csrf $csrf;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id('latch-csrf-test-' . bin2hex(random_bytes(4)));
        session_start();
        $_SESSION = [];

        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function testTokenIsStableWithinSession(): void
    {
        $first = $this->csrf->token();
        $second = $this->csrf->token();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    public function testValidateRejectsMissingOrWrongToken(): void
    {
        $token = $this->csrf->token();

        $this->assertFalse($this->csrf->validate(null));
        $this->assertFalse($this->csrf->validate(''));
        $this->assertFalse($this->csrf->validate('wrong-token'));
        $this->assertTrue($this->csrf->validate($token));
    }

    public function testRotateChangesToken(): void
    {
        $before = $this->csrf->token();
        $this->csrf->rotate();
        $after = $this->csrf->token();

        $this->assertNotSame($before, $after);
        $this->assertFalse($this->csrf->validate($before));
        $this->assertTrue($this->csrf->validate($after));
    }

    public function testValidateAndRotateOnSuccess(): void
    {
        $token = $this->csrf->token();

        $this->assertTrue($this->csrf->validateAndRotate($token));
        $this->assertFalse($this->csrf->validate($token));
        $this->assertNotSame($token, $this->csrf->token());
    }

    public function testFieldEscapesHtml(): void
    {
        $this->session->set('_csrf', '"><script>alert(1)</script>');

        $field = $this->csrf->field();

        $this->assertStringContainsString('name="_csrf"', $field);
        $this->assertStringNotContainsString('<script>', $field);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $field);
    }
}