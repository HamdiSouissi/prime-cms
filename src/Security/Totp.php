<?php

declare(strict_types=1);

namespace Prime\Security;

use InvalidArgumentException;

/**
 * Pure PHP RFC 6238 TOTP (Time-based One-Time Password) and RFC 4648 Base32 implementation.
 *
 * Defaults: HMAC-SHA1, 6 digits, 30-second time step, ±1 step window tolerance,
 * 160-bit (20-byte) secrets, and anti-replay via monotonically increasing time step tracking.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Unambiguous lowercase alphanumeric charset for human-readable recovery codes (no 0/o/1/i/l).
     */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Generate a cryptographically secure Base32 secret (default 160 bits = 20 bytes = 32 Base32 chars).
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new InvalidArgumentException('TOTP secret must be at least 10 bytes (80 bits).');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Group a Base32 secret into 4-character blocks for manual authenticator entry.
     */
    public static function formatSecret(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $secret) ?? $secret);

        return trim(chunk_split($clean, 4, ' '));
    }

    /**
     * Generate a TOTP code from a Base32-encoded secret.
     */
    public static function code(
        string $base32Secret,
        ?int $timestamp = null,
        int $digits = 6,
        int $period = 30,
        string $algorithm = 'sha1',
    ): string {
        $rawSecret = self::base32Decode($base32Secret);

        return self::codeFromRawSecret($rawSecret, $timestamp ?? time(), $digits, $period, $algorithm);
    }

    /**
     * Generate an RFC 6238 TOTP code directly from raw secret bytes.
     *
     * Supports `sha1`, `sha256`, and `sha512` (allowing exact verification against RFC 6238 Appendix B).
     */
    public static function codeFromRawSecret(
        string $rawSecret,
        int $timestamp,
        int $digits = 6,
        int $period = 30,
        string $algorithm = 'sha1',
    ): string {
        if ($digits < 6 || $digits > 10) {
            throw new InvalidArgumentException('TOTP digits must be between 6 and 10.');
        }

        if ($period < 1) {
            throw new InvalidArgumentException('TOTP period must be positive.');
        }

        $step = intdiv($timestamp, $period);

        return self::hotp($rawSecret, $step, $digits, $algorithm);
    }

    /**
     * Verify a user-supplied TOTP code within `±$window` time steps.
     *
     * Enforces anti-replay when `$lastUsedStep` is provided: any matched time step
     * less than or equal to `$lastUsedStep` is rejected.
     *
     * @return int|null The matched time step integer on success (store as `two_factor_last_step`), or null on failure
     */
    public static function verify(
        string $base32Secret,
        string $inputCode,
        ?int $timestamp = null,
        int $window = 1,
        ?int $lastUsedStep = null,
        int $digits = 6,
        int $period = 30,
        string $algorithm = 'sha1',
    ): ?int {
        $cleanCode = preg_replace('/[\s-]+/', '', trim($inputCode)) ?? '';
        if (strlen($cleanCode) !== $digits || !ctype_digit($cleanCode)) {
            return null;
        }

        try {
            $rawSecret = self::base32Decode($base32Secret);
        } catch (InvalidArgumentException) {
            return null;
        }

        $now = $timestamp ?? time();
        $currentStep = intdiv($now, $period);
        $matchedStep = null;

        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidateStep = $currentStep + $offset;
            if ($candidateStep < 0) {
                continue;
            }

            $expected = self::hotp($rawSecret, $candidateStep, $digits, $algorithm);
            if (hash_equals($expected, $cleanCode)) {
                $matchedStep = $candidateStep;
            }
        }

        if ($matchedStep === null) {
            return null;
        }

        if ($lastUsedStep !== null && $matchedStep <= $lastUsedStep) {
            return null;
        }

        return $matchedStep;
    }

    /**
     * Build a standards-compliant `otpauth://totp/...` URI for authenticator apps.
     */
    public static function provisioningUri(
        string $base32Secret,
        string $accountEmail,
        string $issuer = 'Prime CMS',
        int $digits = 6,
        int $period = 30,
    ): string {
        $cleanSecret = strtoupper(preg_replace('/[\s-]+/', '', $base32Secret) ?? $base32Secret);
        $label = rawurlencode($issuer) . ':' . rawurlencode(trim($accountEmail));

        $query = http_build_query([
            'secret' => $cleanSecret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Generate single-use recovery codes formatted as `xxxx-xxxx`.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        $alphabetLen = strlen(self::RECOVERY_ALPHABET);

        for ($i = 0; $i < $count; $i++) {
            $partA = '';
            $partB = '';
            for ($c = 0; $c < 4; $c++) {
                $partA .= self::RECOVERY_ALPHABET[random_int(0, $alphabetLen - 1)];
                $partB .= self::RECOVERY_ALPHABET[random_int(0, $alphabetLen - 1)];
            }
            $codes[] = $partA . '-' . $partB;
        }

        return $codes;
    }

    /**
     * Normalize a recovery code input (`XXXX-XXXX`, `xxxxxxxx`, spaces) to canonical `xxxx-xxxx`.
     */
    public static function normalizeRecoveryCode(string $input): string
    {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $input) ?? '');
        if (strlen($clean) === 8) {
            return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
        }

        return strtolower(trim($input));
    }

    /**
     * RFC 4648 Base32 encoding (without trailing `=` padding, standard for TOTP secrets).
     */
    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $output = '';
        $buffer = 0;
        $bitsLeft = 0;
        $length = strlen($binary);

        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($binary[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $index = ($buffer >> $bitsLeft) & 0x1F;
                $output .= self::BASE32_ALPHABET[$index];
            }
        }

        if ($bitsLeft > 0) {
            $index = ($buffer << (5 - $bitsLeft)) & 0x1F;
            $output .= self::BASE32_ALPHABET[$index];
        }

        return $output;
    }

    /**
     * RFC 4648 Base32 decoding (case-insensitive, ignores spaces/hyphens and optional `=` padding).
     *
     * @throws InvalidArgumentException On invalid Base32 characters
     */
    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $encoded) ?? '');
        $clean = rtrim($clean, '=');

        if ($clean === '') {
            return '';
        }

        $output = '';
        $buffer = 0;
        $bitsLeft = 0;
        $length = strlen($clean);

        for ($i = 0; $i < $length; $i++) {
            $char = $clean[$i];
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException(sprintf('Caractère Base32 invalide : [%s].', $char));
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    /**
     * Compute an RFC 4226 HOTP value for a 64-bit unsigned big-endian counter.
     */
    private static function hotp(string $rawSecret, int $counter, int $digits, string $algorithm): string
    {
        $high = ($counter >> 32) & 0xFFFFFFFF;
        $low = $counter & 0xFFFFFFFF;
        $binaryCounter = pack('NN', $high, $low);

        $hmac = hash_hmac($algorithm, $binaryCounter, $rawSecret, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $binaryCode = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $modulo = 10 ** $digits;
        $otp = $binaryCode % $modulo;

        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }
}
