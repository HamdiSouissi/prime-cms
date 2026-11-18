<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Api\Token\ApiTokenService;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Crée un nouveau jeton d'API pour un utilisateur (`bin/prime api:token:create`).
 *
 * Affiche le jeton en clair une seule fois.
 */
final class ApiTokenCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly UserRepository $users,
    ) {
    }

    public function name(): string
    {
        return 'api:token:create';
    }

    public function description(): string
    {
        return 'Crée un jeton d\'API pour un utilisateur (--user-id, --name, --expires-at).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $userIdRaw = ArgParser::stringOption($opts, 'user-id');
        $name = ArgParser::stringOption($opts, 'name');
        $expiresAtRaw = ArgParser::stringOption($opts, 'expires-at');

        if ($userIdRaw === '' || !ctype_digit($userIdRaw)) {
            $output->error('Option --user-id requise (identifiant utilisateur numérique).');

            return self::FAILURE;
        }

        $userId = (int) $userIdRaw;

        if ($name === '') {
            $output->error('Option --name requise (nom du jeton).');

            return self::FAILURE;
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            $output->error(sprintf('Utilisateur #%d introuvable.', $userId));

            return self::FAILURE;
        }

        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            try {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiresAtRaw);
                if (!$dt instanceof \DateTimeImmutable) {
                    $dt = new \DateTimeImmutable($expiresAtRaw);
                }
                $expiresAt = $dt;
            } catch (\Throwable) {
                $output->error('Option --expires-at invalide (format attendu : YYYY-MM-DD HH:MM:SS).');

                return self::FAILURE;
            }
        }

        try {
            $result = $this->tokens->issue($user, $name, ['*'], $expiresAt);
        } catch (\Throwable $exception) {
            $output->error('Impossible de créer le jeton : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln('Jeton d\'API créé.');
        $output->writeln(sprintf('  Utilisateur : %s (#%d)', $user->displayName, $user->id));
        $output->writeln(sprintf('  Nom         : %s', $name));
        $output->writeln(sprintf('  Jeton       : %s', $result['token']));
        $output->writeln('');
        $output->writeln('Conservez ce jeton en lieu sûr : il ne sera pas affiché à nouveau.');

        return self::SUCCESS;
    }
}
