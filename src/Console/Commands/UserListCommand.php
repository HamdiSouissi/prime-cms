<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Throwable;

/**
 * Lists users in a formatted CLI table (`bin/prime user:list`).
 */
final class UserListCommand implements CommandInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function name(): string
    {
        return 'user:list';
    }

    public function description(): string
    {
        return 'Liste les utilisateurs (--role, --status, --search)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $search = ArgParser::stringOption($opts, 'search');
        $role = ArgParser::stringOption($opts, 'role');
        $status = ArgParser::stringOption($opts, 'status');

        try {
            $result = $this->users->paginate(
                page: 1,
                perPage: 100,
                search: $search,
                roleSlug: $role,
                status: $status,
                sort: 'created_at',
                direction: 'asc',
            );
        } catch (Throwable $exception) {
            $output->error('Impossible de lister les utilisateurs : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($result['items'] === []) {
            $output->writeln('Aucun utilisateur trouvé.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($result['items'] as $user) {
            $roleNames = array_map(static fn ($r): string => $r->slug, $user->roles);
            $rows[] = [
                (string) $user->id,
                $user->displayName,
                $user->email,
                $roleNames !== [] ? implode(', ', $roleNames) : '-',
                $user->status,
                $user->hasTwoFactor() ? 'Oui' : 'Non',
                $user->lastLoginAt ?? 'Jamais',
            ];
        }

        $output->table(['ID', 'Nom', 'E-mail', 'Rôles', 'Statut', '2FA', 'Dernière connexion (UTC)'], $rows);
        $output->writeln(sprintf('Total : %d utilisateur(s).', $result['total']));

        return self::SUCCESS;
    }
}
