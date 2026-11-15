<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Prime\Security\Encrypter;
use Prime\Security\Exception\EncryptionException;

final class EncrypterTest extends TestCase
{
    public function testEncryptAndDecryptRoundTripWithUniqueNonces(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $encrypter = new Encrypter($key);

        $secret = 'JBSWY3DPEHPK3PXP';
        $cipher1 = $encrypter->encrypt($secret);
        $cipher2 = $encrypter->encrypt($secret);

        $this->assertStringStartsWith('v1:', $cipher1);
        $this->assertNotSame($cipher1, $cipher2);
        $this->assertSame($secret, $encrypter->decrypt($cipher1));
        $this->assertSame($secret, $encrypter->decrypt($cipher2));
    }

    public function testTamperedCiphertextOrTagThrowsException(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $encrypter = new Encrypter($key);

        $cipher = $encrypter->encrypt('sensitive-totp-secret');
        $raw = base64_decode(substr($cipher, 3), true);
        $this->assertIsString($raw);

        // Flip one bit in the ciphertext / tag.
        $lastIdx = strlen($raw) - 1;
        $raw[$lastIdx] = chr(ord($raw[$lastIdx]) ^ 0xFF);
        $tampered = 'v1:' . base64_encode($raw);

        $this->expectException(EncryptionException::class);
        $encrypter->decrypt($tampered);
    }

    public function testDecryptWithDifferentKeyThrowsException(): void
    {
        $enc1 = new Encrypter('base64:' . base64_encode(random_bytes(32)));
        $enc2 = new Encrypter('base64:' . base64_encode(random_bytes(32)));

        $cipher = $enc1->encrypt('top-secret');

        $this->expectException(EncryptionException::class);
        $enc2->decrypt($cipher);
    }

    public function testMissingOrInvalidAppKeyThrowsClearException(): void
    {
        $this->expectException(EncryptionException::class);
        new Encrypter('');
    }
}
