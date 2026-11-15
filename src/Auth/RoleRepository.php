<?php

declare(strict_types=1);

namespace Prime\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\Exception\PolicyException;
use Prime\Database\Connection;

/**
 * Data access layer for `pc_roles` and `pc_role_capabilities`.
 */
final class RoleRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Return all roles with their capabilities and assigned user counts.
     *
     * @return list<Role>
     */
    public function all(): array
    {
        $rows = $this->connection->table('roles')
            ->orderBy('is_system', 'DESC')
            ->orderBy('id', 'ASC')
            ->get();

        return $this->hydrateMany($rows);
    }

    public function find(int $id): ?Role
    {
        $row = $this->connection->table('roles')
            ->where('id', '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMany([$row])[0] ?? null;
    }

    public function findBySlug(string $slug): ?Role
    {
        $row = $this->connection->table('roles')
            ->where('slug', '=', strtolower(trim($slug)))
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMany([$row])[0] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return list<Role>
     */
    public function findByIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }

        $rows = $this->connection->table('roles')
            ->whereIn('id', $clean)
            ->orderBy('id', 'ASC')
            ->get();

        return $this->hydrateMany($rows);
    }

    /**
     * Create a custom (non-system) role with its capability list.
     *
     * @param list<string> $capabilities
     */
    public function create(string $slug, string $name, ?string $description, array $capabilities): Role
    {
        $now = $this->nowUtc();
        $cleanSlug = self::slugify($slug);

        $roleId = $this->connection->transaction(function () use ($cleanSlug, $name, $description, $capabilities, $now): int {
            $id = $this->connection->table('roles')->insertGetId([
                'slug' => $cleanSlug,
                'name' => trim($name),
                'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->replaceCapabilities($id, $capabilities);

            return $id;
        });

        $created = $this->find($roleId);
        if ($created === null) {
            throw new PolicyException('Impossible de charger le rôle nouvellement créé.');
        }

        return $created;
    }

    /**
     * Update a role's name, description, and capability list.
     * The `administrator` role is strictly read-only.
     *
     * @param list<string> $capabilities
     */
    public function update(int $id, string $name, ?string $description, array $capabilities): Role
    {
        $role = $this->find($id);
        if ($role === null) {
            throw new PolicyException('Ce rôle est introuvable.');
        }

        if ($role->slug === Role::ADMINISTRATOR_SLUG) {
            throw new PolicyException('Le rôle Administrateur est en lecture seule et ne peut pas être modifié.');
        }

        $now = $this->nowUtc();

        $this->connection->transaction(function () use ($id, $name, $description, $capabilities, $now): void {
            $this->connection->table('roles')
                ->where('id', '=', $id)
                ->update([
                    'name' => trim($name),
                    'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                    'updated_at' => $now,
                ]);

            $this->replaceCapabilities($id, $capabilities);
        });

        $updated = $this->find($id);
        if ($updated === null) {
            throw new PolicyException('Ce rôle est introuvable après mise à jour.');
        }

        return $updated;
    }

    /**
     * Delete a custom role. Refuses system roles and roles still assigned to users.
     */
    public function delete(int $id): void
    {
        $role = $this->find($id);
        if ($role === null) {
            throw new PolicyException('Ce rôle est introuvable.');
        }

        if ($role->isSystem) {
            throw new PolicyException('Les rôles système intégrés ne peuvent pas être supprimés.');
        }

        if ($role->usersCount > 0) {
            $label = $role->usersCount > 1
                ? sprintf('%d utilisateurs possèdent encore ce rôle', $role->usersCount)
                : '1 utilisateur possède encore ce rôle';

            throw new PolicyException(
                sprintf('Impossible de supprimer ce rôle : %s. Réassignez-les d\'abord.', $label),
            );
        }

        $this->connection->table('roles')
            ->where('id', '=', $id)
            ->delete();
    }

    /**
     * Generate a clean lowercase ASCII slug (`[a-z0-9-]`).
     */
    public static function slugify(string $value): string
    {
        $str = mb_strtolower(trim($value), 'UTF-8');
        $trans = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ];
        $str = strtr($str, $trans);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str) ?? '';

        return trim($str, '-');
    }

    /**
     * @param list<string> $capabilities
     */
    private function replaceCapabilities(int $roleId, array $capabilities): void
    {
        $this->connection->table('role_capabilities')
            ->where('role_id', '=', $roleId)
            ->delete();

        $unique = array_values(array_unique(array_filter(
            $capabilities,
            static fn (string $cap): bool => $cap !== '' && $cap !== Capabilities::WILDCARD,
        )));

        foreach ($unique as $capability) {
            $this->connection->table('role_capabilities')->insert([
                'role_id' => $roleId,
                'capability' => $capability,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Role>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $roleIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        $capRows = $this->connection->table('role_capabilities')
            ->whereIn('role_id', $roleIds)
            ->get();

        $capsByRole = [];
        foreach ($capRows as $capRow) {
            $rid = (int) $capRow['role_id'];
            $capsByRole[$rid][] = (string) $capRow['capability'];
        }

        $userRoleRows = $this->connection->table('user_roles')
            ->select(['role_id'])
            ->whereIn('role_id', $roleIds)
            ->get();

        $countsByRole = [];
        foreach ($userRoleRows as $ur) {
            $rid = (int) $ur['role_id'];
            $countsByRole[$rid] = ($countsByRole[$rid] ?? 0) + 1;
        }

        $roles = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $roles[] = new Role(
                id: $id,
                slug: (string) $row['slug'],
                name: (string) $row['name'],
                description: isset($row['description']) && is_string($row['description']) ? $row['description'] : null,
                isSystem: (bool) $row['is_system'],
                capabilities: $capsByRole[$id] ?? [],
                usersCount: $countsByRole[$id] ?? 0,
                createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
                updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            );
        }

        return $roles;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
