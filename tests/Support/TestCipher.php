<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Crypto\Cipher;

/**
 * A keyed stand-in for PhpEncryption: the stored value never contains the plaintext, and another
 * key cannot open it (like a changed _NEW_COOKIE_KEY_).
 */
final class TestCipher implements Cipher
{
    /** @var string */
    private $key;

    public function __construct(string $key = 'test-key')
    {
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $mask = str_repeat(hash('sha256', $this->key, true), (int) ceil(strlen($plaintext) / 32) + 1);

        return 'enc:'.hash_hmac('sha256', $plaintext, $this->key).':'.base64_encode($plaintext ^ substr($mask, 0, strlen($plaintext)));
    }

    public function decrypt(string $ciphertext): ?string
    {
        $parts = explode(':', $ciphertext);
        if (count($parts) !== 3 || $parts[0] !== 'enc') {
            return null;
        }
        $masked = (string) base64_decode($parts[2], true);
        $mask = str_repeat(hash('sha256', $this->key, true), (int) ceil(strlen($masked) / 32) + 1);
        $plain = $masked ^ substr($mask, 0, strlen($masked));

        return $plain !== '' && hash_equals($parts[1], hash_hmac('sha256', $plain, $this->key)) ? $plain : null;
    }
}
