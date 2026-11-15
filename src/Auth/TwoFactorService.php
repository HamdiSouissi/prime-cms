<?php

declare(strict_types=1);

namespace Prime\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Log\Logger;
use Prime\Security\Encrypter;
use Prime\Security\Hasher;
use Prime\Security\Totp;

/**
 * Manages TOTP 2FA setup, confirmation, verification (with anti-replay & single-use recovery codes),
 * recovery code regeneration, and disabling.
 *
 * Secrets are stored encrypted via `Encrypter` (AES-256-GCM) and recovery codes are stored hashed
 * via `Hasher`. Neither secrets nor recovery codes are ever written to logs.
 */
final class TwoFactorService
{
    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    private readonly Hasher $hasher;

    /**
     * @param (Closure(): int)|null $clock Optional Unix timestamp supplier for deterministic tests
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly Encrypter $encrypter,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        ?Closure $clock = null,
        ?Hasher $hasher = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->hasher = $hasher ?? new Hasher();
    }

    /**
     * Generate a fresh 160-bit Base32 TOTP secret and provisioning URI for profile activation.
     *
     * @return array{secret: string, formatted: string, uri: string}
     */
    public function generateSetupSecret(User $user, string $issuer = 'Prime CMS'): array
    {
        $secret = Totp::generateSecret(20);

        return [
            'secret' => $secret,
            'formatted' => Totp::formatSecret($secret),
            'uri' => Totp::provisioningUri($secret, $user->email, $issuer),
        ];
    }

    /**
     * Confirm TOTP setup by verifying a live code, encrypting the secret, generating 8 recovery codes,
     * and storing everything atomically.
     *
     * @return list<string>|null Plaintext single-use recovery codes (displayed ONCE), or null if code is invalid
     */
    public function confirmAndEnable(User $user, string $base32Secret, string $code): ?array
    {
        $matchedStep = Totp::verify($base32Secret, $code, ($this->clock)(), window: 1);
        if ($matchedStep === null) {
            return null;
        }

        $encryptedSecret = $this->encrypter->encrypt($base32Secret);
        $plainRecovery = Totp::generateRecoveryCodes(8);
        $hashedRecovery = $this->hashRecoveryCodes($plainRecovery);
        $nowUtc = $this->formatUtc(($this->clock)());

        $this->connection->table('users')
            ->where('id', '=', $user->id)
            ->update([
                'two_factor_secret' => $encryptedSecret,
                'two_factor_recovery_codes' => json_encode($hashedRecovery, JSON_THROW_ON_ERROR),
                'two_factor_confirmed_at' => $nowUtc,
                'two_factor_last_step' => $matchedStep,
                'updated_at' => $nowUtc,
            ]);

        $this->logger?->notice('Authentification à deux facteurs activée.', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->hooks?->emit('auth.two_factor_enabled', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return $plainRecovery;
    }

    /**
     * Verify a TOTP code (with anti-replay via `two_factor_last_step`) OR consume a single-use recovery code.
     */
    public function verifyChallenge(User $user, string $input): bool
    {
        $raw = $this->users->findRawById($user->id);
        if ($raw === null || empty($raw['two_factor_confirmed_at']) || !is_string($raw['two_factor_secret'])) {
            return false;
        }

        $secret = $this->encrypter->decrypt($raw['two_factor_secret']);
        $lastStep = isset($raw['two_factor_last_step'])
            ? (int) $raw['two_factor_last_step']
            : null;

        // 1. Try 6-digit TOTP code with anti-replay.
        $matchedStep = Totp::verify($secret, $input, ($this->clock)(), window: 1, lastUsedStep: $lastStep);
        if ($matchedStep !== null) {
            $this->connection->table('users')
                ->where('id', '=', $user->id)
                ->update([
                    'two_factor_last_step' => $matchedStep,
                ]);

            return true;
        }

        // 2. Try single-use recovery code (`xxxx-xxxx`).
        $hashes = $this->decodeRecoveryHashes($raw['two_factor_recovery_codes'] ?? null);
        if ($hashes === []) {
            return false;
        }

        $normalized = Totp::normalizeRecoveryCode($input);
        foreach ($hashes as $index => $hash) {
            if ($this->hasher->verify($normalized, $hash)) {
                unset($hashes[$index]);
                $remaining = array_values($hashes);

                $this->connection->table('users')
                    ->where('id', '=', $user->id)
                    ->update([
                        'two_factor_recovery_codes' => json_encode($remaining, JSON_THROW_ON_ERROR),
                        'updated_at' => $this->formatUtc(($this->clock)()),
                    ]);

                $this->logger?->notice('Code de récupération 2FA utilisé.', [
                    'user_id' => $user->id,
                    'remaining_codes' => count($remaining),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Regenerate 8 fresh recovery codes (invalidating all previous ones).
     *
     * @return list<string> Plaintext recovery codes (displayed ONCE)
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = Totp::generateRecoveryCodes(8);
        $hashed = $this->hashRecoveryCodes($plain);

        $this->connection->table('users')
            ->where('id', '=', $user->id)
            ->update([
                'two_factor_recovery_codes' => json_encode($hashed, JSON_THROW_ON_ERROR),
                'updated_at' => $this->formatUtc(($this->clock)()),
            ]);

        $this->logger?->notice('Codes de récupération 2FA régénérés.', [
            'user_id' => $user->id,
        ]);

        return $plain;
    }

    /**
     * Count how many unused recovery codes remain for the user.
     */
    public function remainingRecoveryCodesCount(int $userId): int
    {
        $raw = $this->users->findRawById($userId);

        return count($this->decodeRecoveryHashes($raw['two_factor_recovery_codes'] ?? null));
    }

    /**
     * Disable 2FA for a user (either self-service or administrative/CLI reset).
     */
    public function disable(User $user, ?int $actorUserId = null): void
    {
        $this->connection->table('users')
            ->where('id', '=', $user->id)
            ->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_step' => null,
                'updated_at' => $this->formatUtc(($this->clock)()),
            ]);

        $this->logger?->notice('Authentification à deux facteurs désactivée.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'actor_user_id' => $actorUserId,
        ]);

        $this->hooks?->emit('auth.two_factor_disabled', [
            'user_id' => $user->id,
            'email' => $user->email,
            'actor_user_id' => $actorUserId,
        ]);
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(
            fn (string $code): string => $this->hasher->make(Totp::normalizeRecoveryCode($code)),
            $codes,
        );
    }

    /**
     * @return list<string>
     */
    private function decodeRecoveryHashes(mixed $rawJson): array
    {
        if (!is_string($rawJson) || $rawJson === '') {
            return [];
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $hashes = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $hashes[] = $item;
            }
        }

        return $hashes;
    }

    private function formatUtc(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
