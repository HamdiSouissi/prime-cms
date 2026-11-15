<?php

declare(strict_types=1);

namespace Prime\Auth;

/**
 * Typed result of an authentication or 2FA verification attempt.
 */
final class AuthResult
{
    public const STATUS_AUTHENTICATED = 'authenticated';

    public const STATUS_TWO_FACTOR_REQUIRED = 'two_factor_required';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_TWO_FACTOR_EXPIRED = 'two_factor_expired';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly ?string $message = null,
        public readonly int $retryAfterSeconds = 0,
        public readonly ?int $pendingUserId = null,
    ) {
    }

    public static function authenticated(User $user): self
    {
        return new self(self::STATUS_AUTHENTICATED, user: $user);
    }

    public static function twoFactorRequired(int $userId): self
    {
        return new self(self::STATUS_TWO_FACTOR_REQUIRED, pendingUserId: $userId);
    }

    public static function failed(string $message): self
    {
        return new self(self::STATUS_FAILED, message: $message);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));
        $unit = $minutes > 1 ? 'minutes' : 'minute';

        return new self(
            self::STATUS_LOCKED,
            message: sprintf('Trop de tentatives. Réessayez dans %d %s.', $minutes, $unit),
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public static function twoFactorExpired(string $message): self
    {
        return new self(self::STATUS_TWO_FACTOR_EXPIRED, message: $message);
    }

    public function isAuthenticated(): bool
    {
        return $this->status === self::STATUS_AUTHENTICATED;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->status === self::STATUS_TWO_FACTOR_REQUIRED;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function isTwoFactorExpired(): bool
    {
        return $this->status === self::STATUS_TWO_FACTOR_EXPIRED;
    }
}
