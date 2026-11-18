<?php

declare(strict_types=1);

namespace Prime\Api\Token;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Api\Repository\ApiTokenRepository;
use Prime\Auth\Capabilities;
use Prime\Auth\User;
use Prime\Auth\UserRepository;

/**
 * Service métier pour les jetons d'API REST (bearer tokens).
 *
 * Encapsule l'émission, l'authentification et la révocation des jetons.
 * Le jeton en clair n'est jamais stocké : seule son empreinte SHA-256 est
 * persistée, avec un préfixe de 8 caractères pour l'affichage.
 */
final class ApiTokenService
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly Capabilities $capabilities,
    ) {
    }

    /**
     * Émet un nouveau jeton d'API pour un utilisateur.
     *
     * Les capacités demandées sont filtrées à celles que l'utilisateur possède
     * réellement (un contributeur ne peut pas se donner `settings.manage`).
     * `['*']` est remplacé par la liste complète des capacités de l'utilisateur.
     *
     * @param list<string> $abilities
     * @return array{token: string, id: int}
     */
    public function issue(User $user, string $name, array $abilities, ?DateTimeImmutable $expiresAt = null): array
    {
        // `['*']` signifie « toutes les capacités de l'utilisateur ».
        if ($abilities === ['*']) {
            $abilities = $this->capabilities->keys();
        }

        // Ne garder que les capacités que l'utilisateur possède réellement ($user->can()).
        $actual = array_values(array_filter($abilities, static fn (string $cap): bool => $user->can($cap)));

        $expiresAtString = $expiresAt !== null
            ? $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : null;

        $result = $this->tokens->create(
            userId: $user->id,
            name: $name,
            abilities: $actual,
            expiresAt: $expiresAtString,
        );

        return ['token' => $result['token'], 'id' => $result['id']];
    }

    /**
     * Authentifie un jeton brut et retourne l'utilisateur associé avec ses capacités.
     *
     * Retourne null si le jeton est inconnu, révoqué, expiré ou si l'utilisateur
     * est désactivé.
     *
     * @param string|null $ip Adresse IP du client (pour last_used_ip, optionnel)
     * @return array{user: User, abilities: list<string>, token_id: int}|null
     */
    public function authenticate(string $raw, ?string $ip = null): ?array
    {
        $row = $this->tokens->findByToken($raw);

        if ($row === null) {
            return null;
        }

        if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresTs = strtotime((string) $expiresAt . ' UTC');
            if ($expiresTs !== false && $expiresTs <= time()) {
                return null;
            }
        }

        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        // L'utilisateur est résolu via le repository d'utilisateurs (pas via Auth, qui
        // exigerait une session). Le service vérifie le statut actif.
        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        $abilities = $this->decodeAbilities((string) ($row['abilities'] ?? '[]'));

        // Reconstruire l'utilisateur avec uniquement les capacités effectives du jeton,
        // afin que canPreview/canEdit etc. appliquent automatiquement les limites.
        $effectiveUser = new User(
            id: $user->id,
            email: $user->email,
            displayName: $user->displayName,
            status: $user->status,
            locale: $user->locale,
            sessionVersion: $user->sessionVersion,
            lastLoginAt: $user->lastLoginAt,
            passwordChangedAt: $user->passwordChangedAt,
            twoFactorConfirmedAt: $user->twoFactorConfirmedAt,
            twoFactorLastStep: $user->twoFactorLastStep,
            createdAt: $user->createdAt,
            updatedAt: $user->updatedAt,
            roles: $user->roles,
            capabilities: $abilities,
            avatarMediaId: $user->avatarMediaId,
            username: $user->username,
            bio: $user->bio,
        );

        // Enregistrer la dernière utilisation (throttled : au plus une fois par minute par jeton).
        $lastUsedAt = $row['last_used_at'] ?? null;
        $shouldTouch = true;
        if ($lastUsedAt !== null && $lastUsedAt !== '') {
            $lastUsedTs = strtotime((string) $lastUsedAt . ' UTC');
            if ($lastUsedTs !== false && (time() - $lastUsedTs) < 60) {
                $shouldTouch = false;
            }
        }
        if ($shouldTouch) {
            $this->tokens->touchLastUsed(hash('sha256', $raw), $ip);
        }

        return ['user' => $effectiveUser, 'abilities' => $abilities, 'token_id' => (int) $row['id']];
    }

    /**
     * Révoque un jeton par son ID (définit revoked_at).
     */
    public function revoke(int $id): void
    {
        $this->tokens->revoke($id);
    }

    /**
     * @param string $json
     * @return list<string>
     */
    private function decodeAbilities(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
