<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    public function testEncryptRequiresConfiguredKey(): void
    {
        $configDir = sys_get_temp_dir() . '/latch-cipher-test-' . bin2hex(random_bytes(4));
        mkdir($configDir);
        file_put_contents($configDir . '/default.php', '<?php return [];');

        $cipher = new SecretCipher(new Config($configDir));

        $this->assertFalse($cipher->hasConfiguredKey());

        $this->expectException(\RuntimeException::class);
        $cipher->encrypt('secret');
    }

    public function testEncryptRoundTripWithConfiguredKey(): void
    {
        $key = sodium_crypto_secretbox_keygen();
        $configDir = sys_get_temp_dir() . '/latch-cipher-test-' . bin2hex(random_bytes(4));
        mkdir($configDir);
        file_put_contents($configDir . '/default.php', '<?php return [
            "security" => ["encryption_key" => "' . base64_encode($key) . '"],
        ];');

        $cipher = new SecretCipher(new Config($configDir));
        $this->assertTrue($cipher->hasConfiguredKey());

        $encoded = $cipher->encrypt('totp-secret');
        $this->assertSame('totp-secret', $cipher->decrypt($encoded));
    }
}