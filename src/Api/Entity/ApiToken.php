<?php

declare(strict_types=1);

namespace Prime\Api\Entity;

/**
 * Entité représentant un jeton d'API REST (table `pc_api_tokens`).
 */
final class ApiToken
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $token,
        public readonly ?string $lastUsedAt,
        public readonly ?string $expiresAt,
        public readonly bool $revoked,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * Indique si le jeton est encore valide (non révoqué et non expiré).
     */
    public function isValid(): bool
    {
        if ($this->revoked) {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt !== '') {
            $expiresTs = strtotime($this->expiresAt . ' UTC');
            if ($expiresTs !== false && $expiresTs <= time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            token: (string) $row['token'],
            lastUsedAt: $row['last_used_at'] ?? null,
            expiresAt: $row['expires_at'] ?? null,
            revoked: (int) ($row['revoked'] ?? 0) === 1,
            userAgent: $row['user_agent'] ?? null,
            ip: $row['ip'] ?? null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'token' => $this->token,
            'last_used_at' => $this->lastUsedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => $this->revoked,
            'user_agent' => $this->userAgent,
            'ip' => $this->ip,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
