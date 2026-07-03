<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Plugins\HookRegistry;
use Latch\Core\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testSpanishTranslation(): void
    {
        $translator = new Translator(
            dirname(__DIR__) . '/lang',
            'es',
            new HookRegistry(),
        );

        $this->assertSame('Iniciar sesión', $translator->get('nav.sign_in'));
    }

    public function testFallbackToEnglish(): void
    {
        $translator = new Translator(
            dirname(__DIR__) . '/lang',
            'ar',
            new HookRegistry(),
        );

        $this->assertSame('Sign out', $translator->get('user_menu.sign_out'));
        $this->assertSame('تسجيل الدخول', $translator->get('nav.sign_in'));
    }

    public function testReplacementParameters(): void
    {
        $dir = sys_get_temp_dir() . '/latch-lang-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/en.php', "<?php return ['greet' => ['hello' => 'Hello :name']];");

        $translator = new Translator($dir, 'en', new HookRegistry());
        $this->assertSame('Hello Ada', $translator->get('greet.hello', ['name' => 'Ada']));

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}