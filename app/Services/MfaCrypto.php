<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MfaCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/mfa.php';
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->keyBytes();
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);

        if (!is_string($ciphertext) || $ciphertext === '' || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt MFA secret.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Unable to decrypt MFA secret.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->keyBytes(), OPENSSL_RAW_DATA, $nonce, $tag);

        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('Unable to decrypt MFA secret.');
        }

        return $plaintext;
    }

    public function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->keyBytes());
    }

    public function keyBytes(): string
    {
        $raw = trim((string) ($this->config['encryption_key'] ?? ''));

        if ($raw === '') {
            throw new RuntimeException('MFA_ENCRYPTION_KEY is not configured.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/i', $raw) === 1) {
            $bytes = hex2bin($raw);

            if (is_string($bytes) && strlen($bytes) === 32) {
                return $bytes;
            }
        }

        $decoded = base64_decode($raw, true);

        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }

        throw new RuntimeException('MFA_ENCRYPTION_KEY must be 32 bytes as hex or base64.');
    }
}
