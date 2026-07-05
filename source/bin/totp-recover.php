#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


/**
 * One-off TOTP secret recovery — re-wrap secrets after a failed security-bootstrap.
 * Run as apache: sudo -u apache php bin/totp-recover.php
 */

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\SecretCipher;
use Latch\Models\UserRepository;

define('LATCH_ROOT', dirname(__DIR__));
require LATCH_ROOT . '/vendor/autoload.php';

$config = new Config(LATCH_ROOT . '/config');
$db = new Database((string) $config->get('database.path'));
$users = new UserRepository($db);

$dbPath = (string) $config->get('database.path', 'latch');
$siteUrl = (string) $config->get('site.url', 'latch');
$derivedKey = sodium_crypto_generichash(
    'latch-totp:' . $dbPath . ':' . $siteUrl,
    '',
    SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
);

$configured = trim((string) $config->get('security.encryption_key', ''));
if ($configured === '') {
    fwrite(STDERR, "No security.encryption_key in local.php — nothing to recover.\n");
    exit(1);
}

$configuredKey = base64_decode($configured, true);
if ($configuredKey === false || strlen($configuredKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
    fwrite(STDERR, "Invalid security.encryption_key.\n");
    exit(1);
}

$cipher = new SecretCipher($config);
$fixed = 0;

foreach ($users->listTotpEnabled() as $row) {
    $enc = (string) $row['totp_secret_enc'];
    $plain = $cipher->decrypt($enc);

    if ($plain !== null) {
        fwrite(STDOUT, "User #{$row['id']}: already decryptable with current key.\n");
        continue;
    }

    $raw = base64_decode($enc, true);
    if ($raw === false) {
        fwrite(STDERR, "User #{$row['id']}: invalid ciphertext.\n");
        exit(1);
    }

    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($box, $nonce, $derivedKey);

    if ($plain === false) {
        fwrite(STDERR, "User #{$row['id']}: could not decrypt with derived key either.\n");
        exit(1);
    }

    $users->updateTotpSecretEnc((int) $row['id'], $cipher->encrypt($plain));
    fwrite(STDOUT, "User #{$row['id']}: re-wrapped TOTP secret.\n");
    $fixed++;
}

if ($fixed === 0) {
    fwrite(STDOUT, "No secrets needed recovery.\n");
} else {
    fwrite(STDOUT, "Recovered {$fixed} user(s). Try your authenticator again.\n");
}