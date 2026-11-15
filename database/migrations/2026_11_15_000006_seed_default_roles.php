<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    /**
     * Default system roles and their capabilities.
     *
     * @var list<array{slug: string, name: string, description: string, capabilities: list<string>}>
     */
    private const DEFAULT_ROLES = [
        [
            'slug' => 'administrator',
            'name' => 'Administrateur',
            'description' => 'Accès complet à l\'ensemble du système, des réglages, des utilisateurs et des rôles.',
            'capabilities' => ['*'],
        ],
        [
            'slug' => 'editor',
            'name' => 'Éditeur',
            'description' => 'Gère, publie et supprime tous les contenus et les médias du site.',
            'capabilities' => [
                'dashboard.view',
                'content.view',
                'content.create',
                'content.edit_own',
                'content.edit_any',
                'content.publish',
                'content.delete_own',
                'content.delete_any',
                'media.view',
                'media.upload',
                'media.delete_any',
            ],
        ],
        [
            'slug' => 'author',
            'name' => 'Auteur',
            'description' => 'Crée, modifie, publie et supprime ses propres contenus et importe des médias.',
            'capabilities' => [
                'dashboard.view',
                'content.view',
                'content.create',
                'content.edit_own',
                'content.publish',
                'content.delete_own',
                'media.view',
                'media.upload',
            ],
        ],
        [
            'slug' => 'contributor',
            'name' => 'Contributeur',
            'description' => 'Rédige et modifie ses propres brouillons sans pouvoir publier.',
            'capabilities' => [
                'dashboard.view',
                'content.view',
                'content.create',
                'content.edit_own',
                'media.view',
            ],
        ],
    ];

    public function up(Schema $schema): void
    {
        $connection = $schema->connection();
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $adminRoleId = null;

        foreach (self::DEFAULT_ROLES as $roleDef) {
            $existing = $connection->table('roles')
                ->where('slug', '=', $roleDef['slug'])
                ->first();

            if ($existing !== null) {
                $roleId = (int) $existing['id'];
            } else {
                $roleId = $connection->table('roles')->insertGetId([
                    'slug' => $roleDef['slug'],
                    'name' => $roleDef['name'],
                    'description' => $roleDef['description'],
                    'is_system' => 1,
                    'created_at' => $nowUtc,
                    'updated_at' => $nowUtc,
                ]);
            }

            if ($roleDef['slug'] === 'administrator') {
                $adminRoleId = $roleId;
            }

            foreach ($roleDef['capabilities'] as $capability) {
                $already = $connection->table('role_capabilities')
                    ->where('role_id', '=', $roleId)
                    ->where('capability', '=', $capability)
                    ->exists();

                if (!$already) {
                    $connection->table('role_capabilities')->insert([
                        'role_id' => $roleId,
                        'capability' => $capability,
                    ]);
                }
            }
        }

        // Assign the `administrator` role to all pre-existing users without it.
        if ($adminRoleId !== null) {
            $users = $connection->table('users')->select(['id'])->get();
            foreach ($users as $userRow) {
                $userId = (int) $userRow['id'];
                $assigned = $connection->table('user_roles')
                    ->where('user_id', '=', $userId)
                    ->where('role_id', '=', $adminRoleId)
                    ->exists();

                if (!$assigned) {
                    $connection->table('user_roles')->insert([
                        'user_id' => $userId,
                        'role_id' => $adminRoleId,
                    ]);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $connection = $schema->connection();
        $slugs = array_map(static fn (array $role): string => $role['slug'], self::DEFAULT_ROLES);

        $connection->table('roles')
            ->whereIn('slug', $slugs)
            ->delete();
    }
};
