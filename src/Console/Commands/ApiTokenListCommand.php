<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Api\Repository\ApiTokenRepository;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Liste les jetons d'API d'un utilisateur (`bin/prime api:token:list`).
 */
final class ApiTokenListCommand implements CommandInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly UserRepository $users,
    ) {
    }

    public function name(): string
    {
        return 'api:token:list';
    }

    public function description(): string
    {
        return 'Liste les jetons d\'API d\'un utilisateur (--user avec son email).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $email = ArgParser::stringOption($opts, 'user');
        if ($email === '') {
            $output->error('Option --user requise (email utilisateur).');

            return self::FAILURE;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $output->error(sprintf('Utilisateur %s introuvable.', $email));

            return self::FAILURE;
        }

        $tokenList = $this->tokens->listByUser($user->id);

        if ($tokenList === []) {
            $output->writeln(sprintf('Aucun jeton d\'API pour l\'utilisateur %s (%s).', $email, $user->displayName));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tokenList as $row) {
            $rows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['token_prefix'] ?? ''),
                (string) ($row['created_at'] ?? '-'),
                (string) ($row['expires_at'] ?? '-'),
                (string) ($row['last_used_at'] ?? '-'),
            ];
        }

        $output->writeln(sprintf('Jeton(s) d\'API pour l\'utilisateur %s (%s) :', $email, $user->displayName));
        $output->table(['ID', 'Nom', 'Préfixe', 'Créé le', 'Expire le', 'Dernière utilisation'], $rows);

        return self::SUCCESS;
    }
}
