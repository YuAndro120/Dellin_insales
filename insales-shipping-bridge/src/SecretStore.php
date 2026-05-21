<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Обратимое шифрование PAT (ключ — BRIDGE_SECRET в .env).
 */
final class SecretStore
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain, string $secret): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::deriveKey($secret);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded, string $secret): string
    {
        if ($encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid encrypted value');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = self::deriveKey($secret);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plain;
    }

    private static function deriveKey(string $secret): string
    {
        if ($secret === '') {
            throw new \RuntimeException('BRIDGE_SECRET required to store PAT');
        }

        return hash('sha256', $secret, true);
    }
}
