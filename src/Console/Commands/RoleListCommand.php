<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\RoleRepository;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Throwable;

/**
 * Lists all roles with system status, capability count, and assigned users (`bin/prime role:list`).
 */
final class RoleListCommand implements CommandInterface
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function name(): string
    {
        return 'role:list';
    }

    public function description(): string
    {
        return 'Liste tous les rôles, leurs permissions et le nombre d\'utilisateurs assignés';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $allRoles = $this->roles->all();
        } catch (Throwable $exception) {
            $output->error('Impossible de lister les rôles : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($allRoles === []) {
            $output->writeln('Aucun rôle enregistré.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($allRoles as $role) {
            $rows[] = [
                (string) $role->id,
                $role->slug,
                $role->name,
                $role->isSystem ? 'Oui' : 'Non',
                $role->isAdministrator() ? 'Toutes (*)' : (string) count($role->capabilities),
                (string) $role->usersCount,
            ];
        }

        $output->table(['ID', 'Slug', 'Nom', 'Système', 'Permissions', 'Utilisateurs'], $rows);

        return self::SUCCESS;
    }
}
