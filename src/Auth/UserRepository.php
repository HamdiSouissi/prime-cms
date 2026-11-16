<?php

declare(strict_types=1);

namespace Prime\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Security\Hasher;

/**
 * Data access layer for `pc_users`, `pc_user_roles`, and `pc_remember_tokens`.
 */
final class UserRepository
{
    private readonly Hasher $hasher;

    public function __construct(
        private readonly Connection $connection,
        private readonly RoleRepository $roles,
        ?Hasher $hasher = null,
    ) {
        $this->hasher = $hasher ?? new Hasher();
    }

    public function find(int $id): ?User
    {
        $row = $this->connection->table('users')
            ->where('id', '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateMany([$row])[0] ?? null;
    }

    /**
     * Batch-load users keyed by ID (avoids N+1 queries on public entry lists).
     *
     * @param list<int> $ids
     * @return array<int, User>
     */
    public function findByIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($clean === []) {
            return [];
        }

        $rows = $this->connection->table('users')
            ->whereIn('id', $clean)
            ->get();

        $map = [];
        foreach ($this->hydrateMany($rows) as $user) {
            $map[$user->id] = $user;
        }

        return $map;
    }

    /**
     * Resolve an active user by public author slug (`username` column or slugified `display_name`).
     */
    public function findByAuthorSlug(string $slug): ?User
    {
        $clean = strtolower(trim($slug));
        if ($clean === '') {
            return null;
        }

        try {
            $row = $this->connection->table('users')
                ->where('username', '=', $clean)
                ->first();
            if ($row !== null) {
                return $this->hydrateMany([$row])[0] ?? null;
            }
        } catch (\Throwable) {
            // `username` column may not exist on older test schemas; fall through to display_name scan.
        }

        foreach ($this->all() as $user) {
            if ($user->authorSlug() === $clean) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->findRawByEmail($email);
        if ($row === null) {
            return null;
        }

        return $this->hydrateMany([$row])[0] ?? null;
    }

    /**
     * Internal lookup returning sensitive auth columns (for `Auth` and `TwoFactorService` only).
     *
     * @return array<string, mixed>|null
     */
    public function findRawById(int $id): ?array
    {
        return $this->connection->table('users')
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * Internal lookup returning sensitive auth columns by normalized email.
     *
     * @return array<string, mixed>|null
     */
    public function findRawByEmail(string $email): ?array
    {
        return $this->connection->table('users')
            ->where('email', '=', self::normalizeEmail($email))
            ->first();
    }

    /**
     * Return all users (ordered by ID ascending) for CLI listing.
     *
     * @return list<User>
     */
    public function all(): array
    {
        $rows = $this->connection->table('users')
            ->orderBy('id', 'ASC')
            ->get();

        return $this->hydrateMany($rows);
    }

    /**
     * Paginated, filtered, and sorted user list for `/admin/users`.
     *
     * @return array{items: list<User>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginate(
        int $page = 1,
        int $perPage = 20,
        string $search = '',
        string $roleSlug = '',
        string $status = '',
        string $sort = 'created_at',
        string $direction = 'desc',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $allowedSorts = [
            'display_name' => 'display_name',
            'email' => 'email',
            'status' => 'status',
            'last_login_at' => 'last_login_at',
            'created_at' => 'created_at',
        ];
        $sortCol = $allowedSorts[$sort] ?? 'created_at';
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $roleUserIds = null;
        if ($roleSlug !== '') {
            $role = $this->roles->findBySlug($roleSlug);
            if ($role === null) {
                return [
                    'items' => [],
                    'total' => 0,
                    'page' => $page,
                    'perPage' => $perPage,
                    'totalPages' => 1,
                ];
            }

            $urRows = $this->connection->table('user_roles')
                ->select(['user_id'])
                ->where('role_id', '=', $role->id)
                ->get();

            $roleUserIds = array_map(static fn (array $r): int => (int) $r['user_id'], $urRows);
            if ($roleUserIds === []) {
                return [
                    'items' => [],
                    'total' => 0,
                    'page' => $page,
                    'perPage' => $perPage,
                    'totalPages' => 1,
                ];
            }
        }

        $query = $this->connection->table('users');

        if ($roleUserIds !== null) {
            $query->whereIn('id', $roleUserIds);
        }

        if ($status === User::STATUS_ACTIVE || $status === User::STATUS_DISABLED) {
            $query->where('status', '=', $status);
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $trimmedSearch) . '%';
            $query->where(static function ($nested) use ($like): void {
                $nested->where('display_name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like);
            });
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $rows = $query
            ->orderBy($sortCol, $dir)
            ->orderBy('id', 'ASC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'items' => $this->hydrateMany($rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Count active users holding the `administrator` role (or wildcard `*` capability).
     */
    public function countActiveAdministrators(): int
    {
        $adminRoleIds = $this->administratorRoleIds();
        if ($adminRoleIds === []) {
            return 0;
        }

        $urRows = $this->connection->table('user_roles')
            ->select(['user_id'])
            ->whereIn('role_id', $adminRoleIds)
            ->get();

        $candidateIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $urRows)));
        if ($candidateIds === []) {
            return 0;
        }

        return $this->connection->table('users')
            ->whereIn('id', $candidateIds)
            ->where('status', '=', User::STATUS_ACTIVE)
            ->count();
    }

    /**
     * Create a new user with hashed password and assigned roles.
     *
     * @param list<int> $roleIds
     */
    public function create(
        string $email,
        string $displayName,
        string $plainPassword,
        array $roleIds,
        string $status = User::STATUS_ACTIVE,
        string $locale = 'fr',
    ): User {
        $now = $this->nowUtc();
        $hash = $this->hasher->make($plainPassword);
        $cleanEmail = self::normalizeEmail($email);

        $userId = $this->connection->transaction(function () use ($cleanEmail, $displayName, $hash, $roleIds, $status, $locale, $now): int {
            $id = $this->connection->table('users')->insertGetId([
                'email' => $cleanEmail,
                'password_hash' => $hash,
                'display_name' => trim($displayName),
                'status' => $status,
                'locale' => $locale,
                'session_version' => 1,
                'last_login_at' => null,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncRolesInternal($id, $roleIds);

            return $id;
        });

        $created = $this->find($userId);
        if ($created === null) {
            throw new \RuntimeException('Impossible de charger l\'utilisateur créé.');
        }

        return $created;
    }

    /**
     * Update profile/account metadata fields.
     *
     * @param array<string, mixed> $fields
     */
    public function updateFields(int $userId, array $fields): void
    {
        if (isset($fields['email']) && is_string($fields['email'])) {
            $fields['email'] = self::normalizeEmail($fields['email']);
        }
        if (isset($fields['display_name']) && is_string($fields['display_name'])) {
            $fields['display_name'] = trim($fields['display_name']);
        }
        $fields['updated_at'] = $this->nowUtc();

        $this->connection->table('users')
            ->where('id', '=', $userId)
            ->update($fields);
    }

    /**
     * Replace a user's roles atomically.
     *
     * @param list<int> $roleIds
     */
    public function syncRoles(int $userId, array $roleIds): void
    {
        $this->connection->transaction(function () use ($userId, $roleIds): void {
            $this->syncRolesInternal($userId, $roleIds);
        });
    }

    /**
     * Update a user's password hash, timestamp, increment `session_version`, and revoke remember tokens.
     *
     * @return int The newly incremented `session_version`
     */
    public function updatePassword(int $userId, string $plainPassword): int
    {
        $now = $this->nowUtc();
        $hash = $this->hasher->make($plainPassword);

        return $this->connection->transaction(function () use ($userId, $hash, $now): int {
            $raw = $this->findRawById($userId);
            $nextVersion = ((int) ($raw['session_version'] ?? 1)) + 1;

            $this->connection->table('users')
                ->where('id', '=', $userId)
                ->update([
                    'password_hash' => $hash,
                    'password_changed_at' => $now,
                    'session_version' => $nextVersion,
                    'updated_at' => $now,
                ]);

            $this->revokeRememberTokens($userId);

            return $nextVersion;
        });
    }

    /**
     * Increment a user's `session_version` and revoke all remember tokens (invalidates all sessions).
     *
     * @return int The new `session_version`
     */
    public function incrementSessionVersionAndRevokeTokens(int $userId): int
    {
        return $this->connection->transaction(function () use ($userId): int {
            $raw = $this->findRawById($userId);
            $nextVersion = ((int) ($raw['session_version'] ?? 1)) + 1;

            $this->connection->table('users')
                ->where('id', '=', $userId)
                ->update([
                    'session_version' => $nextVersion,
                    'updated_at' => $this->nowUtc(),
                ]);

            $this->revokeRememberTokens($userId);

            return $nextVersion;
        });
    }

    public function revokeRememberTokens(int $userId): void
    {
        $this->connection->table('remember_tokens')
            ->where('user_id', '=', $userId)
            ->delete();
    }

    public function delete(int $userId): void
    {
        $this->connection->table('users')
            ->where('id', '=', $userId)
            ->delete();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * @param list<int> $roleIds
     */
    private function syncRolesInternal(int $userId, array $roleIds): void
    {
        $this->connection->table('user_roles')
            ->where('user_id', '=', $userId)
            ->delete();

        $unique = array_values(array_unique(array_filter($roleIds, static fn (int $id): bool => $id > 0)));
        foreach ($unique as $roleId) {
            $this->connection->table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function administratorRoleIds(): array
    {
        $all = $this->roles->all();
        $ids = [];
        foreach ($all as $role) {
            if ($role->isAdministrator()) {
                $ids[] = $role->id;
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<User>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $userIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $allRoles = [];
        foreach ($this->roles->all() as $role) {
            $allRoles[$role->id] = $role;
        }

        $userRoleRows = $this->connection->table('user_roles')
            ->whereIn('user_id', $userIds)
            ->get();

        $rolesByUser = [];
        foreach ($userRoleRows as $ur) {
            $uid = (int) $ur['user_id'];
            $rid = (int) $ur['role_id'];
            if (isset($allRoles[$rid])) {
                $rolesByUser[$uid][] = $allRoles[$rid];
            }
        }

        $users = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $userRoles = $rolesByUser[$id] ?? [];

            $capabilities = [];
            foreach ($userRoles as $role) {
                foreach ($role->capabilities as $cap) {
                    $capabilities[$cap] = true;
                }
            }

            $users[] = new User(
                id: $id,
                email: (string) $row['email'],
                displayName: (string) $row['display_name'],
                status: (string) $row['status'],
                locale: (string) ($row['locale'] ?? 'fr'),
                sessionVersion: (int) ($row['session_version'] ?? 1),
                lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
                passwordChangedAt: isset($row['password_changed_at']) ? (string) $row['password_changed_at'] : null,
                twoFactorConfirmedAt: isset($row['two_factor_confirmed_at']) ? (string) $row['two_factor_confirmed_at'] : null,
                twoFactorLastStep: isset($row['two_factor_last_step']) ? (int) $row['two_factor_last_step'] : null,
                createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
                updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
                roles: $userRoles,
                capabilities: array_keys($capabilities),
                avatarMediaId: isset($row['avatar_media_id']) && $row['avatar_media_id'] !== '' ? (int) $row['avatar_media_id'] : null,
                username: isset($row['username']) && is_string($row['username']) && trim($row['username']) !== '' ? trim($row['username']) : null,
                bio: isset($row['bio']) && is_string($row['bio']) && trim($row['bio']) !== '' ? trim($row['bio']) : null,
            );
        }

        return $users;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
