<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;

/**
 * Crée un nouveau jeton d'API pour un utilisateur (`bin/prime api:token:create`).
 *
 * L'identifiant utilisateur est passé en argument (email). Les capacités demandées
 * sont filtrées à celles que l'utilisateur possède réellement.
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
        return 'Crée un jeton d\'API pour un utilisateur (email en argument, --name, --abilities, --days).';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];
        $positionals = $parsed['positionals'];

        $email = $positionals[0] ?? '';
        if ($email === '') {
            $output->error('Identifiant utilisateur (email) requis en argument.');

            return self::FAILURE;
        }

        $name = ArgParser::stringOption($opts, 'name');
        if ($name === '') {
            $output->error('Option --name requise.');

            return self::FAILURE;
        }

        $abilitiesRaw = ArgParser::stringOption($opts, 'abilities', '*');
        $abilities = $abilitiesRaw === '' ? ['*'] : array_values(array_filter(explode(',', $abilitiesRaw)));

        $expiresAt = null;
        $daysRaw = ArgParser::stringOption($opts, 'days');
        if ($daysRaw !== '') {
            $days = (int) $daysRaw;
            if ($days > 0) {
                $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->add(new DateInterval('P' . $days . 'D'));
            }
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $output->error(sprintf('Utilisateur %s introuvable.', $email));

            return self::FAILURE;
        }

        $result = $this->tokens->issue($user, $name, $abilities, $expiresAt);

        $output->writeln('Jeton d\'API créé.');
        $output->writeln(sprintf('  Utilisateur : %s (#%d)', $user->displayName, $user->id));
        $output->writeln(sprintf('  Nom         : %s', $name));
        $output->writeln(sprintf('  Jeton       : %s', $result['token']));

        return self::SUCCESS;
    }
}
