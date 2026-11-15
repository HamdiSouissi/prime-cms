<?php

declare(strict_types=1);

namespace Prime\Security;

use Prime\Security\Exception\EncryptionException;

/**
 * Authenticated symmetric encryption using AES-256-GCM (OpenSSL).
 *
 * Payload format: `v1:<base64(12-byte nonce || 16-byte tag || ciphertext)>`.
 * The encryption key is derived from `APP_KEY` via HKDF-SHA256 so that
 * `APP_KEY` is never passed raw to multiple cryptographic primitives.
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    private const VERSION = 'v1';

    private const NONCE_LENGTH = 12;

    private const TAG_LENGTH = 16;

    private readonly string $key;

    public function __construct(?string $appKey)
    {
        if (!extension_loaded('openssl')) {
            throw new EncryptionException('L\'extension PHP OpenSSL est requise pour le chiffrement.');
        }

        $raw = $this->parseAppKey($appKey);
        $this->key = hash_hkdf('sha256', $raw, 32, 'prime-cms:encrypter:v1');
    }

    /**
     * Encrypt a plaintext value into a versioned, authenticated payload string.
     *
     * @throws EncryptionException
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION,
            self::TAG_LENGTH,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new EncryptionException('Échec du chiffrement des données.');
        }

        return self::VERSION . ':' . base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt and authenticate a payload produced by `encrypt()`.
     *
     * @throws EncryptionException On malformed payload, wrong version, wrong key, or tampering
     */
    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 2);
        if (count($parts) !== 2 || $parts[0] !== self::VERSION || $parts[1] === '') {
            throw new EncryptionException('Format de donnée chiffrée invalide ou version non prise en charge.');
        }

        $decoded = base64_decode($parts[1], true);
        if ($decoded === false || strlen($decoded) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new EncryptionException('Donnée chiffrée corrompue ou tronquée.');
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $tag = substr($decoded, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION,
        );

        if ($plaintext === false) {
            throw new EncryptionException('Impossible de déchiffrer les données (clé invalide ou donnée altérée).');
        }

        return $plaintext;
    }

    private function parseAppKey(?string $appKey): string
    {
        $trimmed = trim((string) $appKey);
        if ($trimmed === '') {
            throw new EncryptionException('APP_KEY est absente ou vide : chiffrement impossible.');
        }

        if (str_starts_with($trimmed, 'base64:')) {
            $decoded = base64_decode(substr($trimmed, 7), true);
            if ($decoded === false || strlen($decoded) < 16) {
                throw new EncryptionException('APP_KEY base64 invalide ou trop courte.');
            }

            return $decoded;
        }

        if (strlen($trimmed) < 16) {
            throw new EncryptionException('APP_KEY doit contenir au moins 16 octets.');
        }

        return $trimmed;
    }
}
