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
        return 'Liste les jetons d\'API d\'un utilisateur (--user-id).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $userIdRaw = ArgParser::stringOption($opts, 'user-id');

        if ($userIdRaw === '' || !ctype_digit($userIdRaw)) {
            $output->error('Option --user-id requise (identifiant utilisateur numérique).');

            return self::FAILURE;
        }

        $userId = (int) $userIdRaw;

        $user = $this->users->find($userId);
        if ($user === null) {
            $output->error(sprintf('Utilisateur #%d introuvable.', $userId));

            return self::FAILURE;
        }

        $tokenList = $this->tokens->listByUser($userId);

        if ($tokenList === []) {
            $output->writeln(sprintf('Aucun jeton d\'API pour l\'utilisateur #%d (%s).', $userId, $user->displayName));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tokenList as $row) {
            $rows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['prefix'] ?? ''),
                (string) ($row['created_at'] ?? '-'),
                (string) ($row['expires_at'] ?? '-'),
                (string) ($row['last_used_at'] ?? '-'),
            ];
        }

        $output->writeln(sprintf('Jeton(s) d\'API pour l\'utilisateur #%d (%s) :', $userId, $user->displayName));
        $output->table(['ID', 'Nom', 'Préfixe', 'Créé le', 'Expire le', 'Dernière utilisation'], $rows);

        return self::SUCCESS;
    }
}
