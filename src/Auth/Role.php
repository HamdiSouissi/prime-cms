<?php

declare(strict_types=1);

namespace Prime\Auth;

/**
 * Immutable Role value object with capabilities and assigned user count.
 */
final class Role
{
    public const ADMINISTRATOR_SLUG = 'administrator';

    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $isSystem,
        public readonly array $capabilities = [],
        public readonly int $usersCount = 0,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    public function isAdministrator(): bool
    {
        return $this->slug === self::ADMINISTRATOR_SLUG || in_array(Capabilities::WILDCARD, $this->capabilities, true);
    }

    public function hasCapability(string $capability): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        return in_array($capability, $this->capabilities, true);
    }
}
