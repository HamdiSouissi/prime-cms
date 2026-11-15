<?php

declare(strict_types=1);

namespace Prime\Auth;

/**
 * Immutable authenticated/managed User value object.
 *
 * Intentionally excludes sensitive columns (`password_hash`, `two_factor_secret`,
 * `two_factor_recovery_codes`) so templates and logs can never accidentally leak them.
 */
final class User
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    private const AVATAR_ACCENTS = ['brand', 'blue', 'violet', 'teal', 'emerald', 'rose', 'amber'];

    /**
     * @param list<Role> $roles
     * @param list<string> $capabilities
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
        public readonly string $locale,
        public readonly int $sessionVersion,
        public readonly ?string $lastLoginAt,
        public readonly ?string $passwordChangedAt,
        public readonly ?string $twoFactorConfirmedAt,
        public readonly ?int $twoFactorLastStep,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly array $roles = [],
        public readonly array $capabilities = [],
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasTwoFactor(): bool
    {
        return $this->twoFactorConfirmedAt !== null && $this->twoFactorConfirmedAt !== '';
    }

    public function isAdministrator(): bool
    {
        if (in_array(Capabilities::WILDCARD, $this->capabilities, true)) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->isAdministrator()) {
                return true;
            }
        }

        return false;
    }

    public function can(string $capability): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->isAdministrator()) {
            return true;
        }

        return in_array($capability, $this->capabilities, true);
    }

    public function cannot(string $capability): bool
    {
        return !$this->can($capability);
    }

    public function hasRole(string $slug): bool
    {
        foreach ($this->roles as $role) {
            if ($role->slug === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function roleIds(): array
    {
        return array_map(static fn (Role $role): int => $role->id, $this->roles);
    }

    public function primaryRoleName(): string
    {
        if ($this->roles === []) {
            return 'Sans rôle';
        }

        foreach ($this->roles as $role) {
            if ($role->isAdministrator()) {
                return $role->name;
            }
        }

        return $this->roles[0]->name;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->displayName)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if ($parts === []) {
            return strtoupper(mb_substr($this->email, 0, 2, 'UTF-8'));
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2, 'UTF-8'), 'UTF-8');
        }

        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $last = mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8');

        return mb_strtoupper($first . $last, 'UTF-8');
    }

    /**
     * Deterministic vibrant Design System accent color for this user's avatar.
     */
    public function avatarAccent(): string
    {
        $hash = crc32(strtolower($this->email));

        return self::AVATAR_ACCENTS[abs($hash) % count(self::AVATAR_ACCENTS)];
    }
}
