<?php

declare(strict_types=1);

namespace Prime\Api\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;

/**
 * Data access layer pour les jetons d'API (`api_tokens`).
 */
final class ApiTokenRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Crée un nouveau jeton d'API pour un utilisateur.
     *
     * @param list<string> $abilities
     * @return array{token: string, token_hash: string, token_prefix: string, id: int}
     */
    public function create(
        int $userId,
        string $name,
        array $abilities,
        ?string $expiresAt = null,
        ?string $lastUsedIp = null,
    ): array {
        $raw = bin2hex(random_bytes(20)); // 40 hex chars
        $token = 'pcat_' . substr($raw, 0, 8) . '_' . $raw;
        $tokenHash = hash('sha256', $token);
        $tokenPrefix = substr($token, 5, 8);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->table('api_tokens')->insert([
            'user_id' => $userId,
            'name' => $name,
            'token_prefix' => $tokenPrefix,
            'token_hash' => $tokenHash,
            'abilities' => json_encode($abilities),
            'last_used_at' => null,
            'last_used_ip' => $lastUsedIp,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->table('api_tokens')
            ->where('token_hash', '=', $tokenHash)
            ->value('id');

        return ['token' => $token, 'token_hash' => $tokenHash, 'token_prefix' => $tokenPrefix, 'id' => $id];
    }

    /**
     * Trouve un jeton par sa valeur brute (non hachée).
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        return $this->db->table('api_tokens')
            ->where('token_hash', '=', $tokenHash)
            ->first();
    }

    /**
     * Trouve un jeton par son ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('api_tokens')
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * Liste tous les jetons d'un utilisateur (sans la valeur brute du jeton).
     *
     * @return list<array<string, mixed>>
     */
    public function listByUser(int $userId): array
    {
        return $this->db->table('api_tokens')
            ->where('user_id', '=', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Révoque un jeton par son ID (définit revoked_at).
     */
    public function revoke(int $id): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $result = $this->db->table('api_tokens')
            ->where('id', '=', $id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        return $result > 0;
    }

    /**
     * Révoque (définit revoked_at) tous les jetons d'un utilisateur.
     */
    public function revokeAllByUser(int $userId): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $result = $this->db->table('api_tokens')
            ->where('user_id', '=', $userId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        return (int) $result;
    }

    /**
     * Supprime un jeton par son ID.
     */
    public function delete(int $id): bool
    {
        $result = $this->db->table('api_tokens')
            ->where('id', '=', $id)
            ->delete();

        return $result > 0;
    }

    /**
     * Met à jour la date de dernière utilisation d'un jeton.
     */
    public function touchLastUsed(string $tokenHash, ?string $ip = null): void
    {
        $this->db->table('api_tokens')
            ->where('token_hash', '=', $tokenHash)
            ->update([
                'last_used_at' => $this->now(),
                'last_used_ip' => $ip,
                'updated_at' => $this->now(),
            ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
