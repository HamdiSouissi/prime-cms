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

    /**
     * French names and descriptions seeded for the system roles. While an administrator keeps
     * them unchanged, they are shown in the user's language (`core.roles.{slug}.*`); a renamed
     * role keeps the text typed by the administrator.
     *
     * @var array<string, array{name: string, description: string}>
     */
    private const SEEDED = [
        'administrator' => ['name' => 'Administrateur', 'description' => 'Accès complet à l\'ensemble du système, des réglages, des utilisateurs et des rôles.'],
        'editor' => ['name' => 'Éditeur', 'description' => 'Gère, publie et supprime tous les contenus et les médias du site.'],
        'author' => ['name' => 'Auteur', 'description' => 'Crée, modifie, publie et supprime ses propres contenus et importe des médias.'],
        'contributor' => ['name' => 'Contributeur', 'description' => 'Rédige et modifie ses propres brouillons sans pouvoir publier.'],
    ];

    /**
     * Display name in the active locale.
     */
    public function label(): string
    {
        if ($this->isSystem && isset(self::SEEDED[$this->slug]) && self::SEEDED[$this->slug]['name'] === $this->name) {
            return t('core.roles.' . $this->slug . '.name');
        }

        return $this->name;
    }

    /**
     * Description in the active locale (null when the role has none).
     */
    public function summary(): ?string
    {
        if ($this->description !== null && $this->isSystem && isset(self::SEEDED[$this->slug])
            && self::SEEDED[$this->slug]['description'] === $this->description) {
            return t('core.roles.' . $this->slug . '.description');
        }

        return $this->description;
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
