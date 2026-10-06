<?php

declare(strict_types=1);

namespace CodexMultitenant\TransportTelegram;

use RuntimeException;

final class BotTokenCipher
{
    private string $key;

    public function __construct(string $encoded_key)
    {
        $key = base64_decode(trim($encoded_key), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Telegram bot token encryption key must be base64-encoded 32 bytes');
        }
        $this->key = $key;
    }

    public function encrypt(string $token): string
    {
        if (trim($token) === '') {
            throw new RuntimeException('Telegram bot token cannot be empty');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, $this->key));
    }

    public function decrypt(string $encrypted_token): string
    {
        $payload = base64_decode($encrypted_token, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted Telegram bot token is invalid');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('Cannot decrypt Telegram bot token');
        }
        return $plaintext;
    }
}
