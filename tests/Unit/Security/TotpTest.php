<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Security\Totp;

final class TotpTest extends TestCase
{
    /**
     * Official RFC 6238 Appendix B test vectors for HMAC-SHA1 (8-digit and 6-digit truncation).
     * Secret = ASCII "12345678901234567890" (20 bytes).
     */
    public function testRfc6238AppendixBSha1Vectors(): void
    {
        $rawSecret = '12345678901234567890';
        $base32Secret = Totp::base32Encode($rawSecret);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $base32Secret);

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expected8Digits) {
            $actual8 = Totp::codeFromRawSecret($rawSecret, $timestamp, digits: 8, period: 30, algorithm: 'sha1');
            $this->assertSame($expected8Digits, $actual8, "RFC 6238 SHA-1 vector failed for T={$timestamp}");

            // 6-digit TOTP must equal the last 6 digits of the 8-digit TOTP.
            $actual6 = Totp::code($base32Secret, $timestamp, digits: 6, period: 30, algorithm: 'sha1');
            $this->assertSame(substr($expected8Digits, -6), $actual6);
        }
    }

    public function testVerifyWindowToleranceAndAntiReplay(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));

        $t = 1_700_000_015; // step = intdiv(t, 30)
        $currentStep = intdiv($t, 30);

        $codePrev = Totp::code($secret, $t - 30);
        $codeCurr = Totp::code($secret, $t);
        $codeNext = Totp::code($secret, $t + 30);
        $codeTooOld = Totp::code($secret, $t - 90);

        // Within ±1 step accepted
        $this->assertSame($currentStep - 1, Totp::verify($secret, $codePrev, $t));
        $this->assertSame($currentStep, Totp::verify($secret, $codeCurr, $t));
        $this->assertSame($currentStep + 1, Totp::verify($secret, $codeNext, $t));

        // Outside ±1 step rejected
        $this->assertNull(Totp::verify($secret, $codeTooOld, $t));

        // Anti-replay: once `currentStep` is recorded as `lastUsedStep`, same or older step is rejected!
        $this->assertNull(Totp::verify($secret, $codeCurr, $t, window: 1, lastUsedStep: $currentStep));
        $this->assertNull(Totp::verify($secret, $codePrev, $t, window: 1, lastUsedStep: $currentStep));
        $this->assertSame($currentStep + 1, Totp::verify($secret, $codeNext, $t, window: 1, lastUsedStep: $currentStep));
    }

    public function testProvisioningUriFormattingAndRecoveryCodes(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $this->assertSame('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP', Totp::formatSecret($secret));

        $uri = Totp::provisioningUri($secret, 'camille@example.fr', 'Prime CMS');
        $this->assertStringStartsWith('otpauth://totp/Prime%20CMS:camille%40example.fr?', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Prime%20CMS', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);

        $recovery = Totp::generateRecoveryCodes(8);
        $this->assertCount(8, $recovery);
        foreach ($recovery as $code) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}$/', $code);
        }
        $this->assertSame('abcd-efgh', Totp::normalizeRecoveryCode(' ABCD EFGH '));
    }

    public function testBase32DecodeRejectsInvalidCharacter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::base32Decode('INVALID!CHAR');
    }
}
