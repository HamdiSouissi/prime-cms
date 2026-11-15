<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Throwable;

/**
 * Re-enables a disabled user account (`bin/prime user:enable`).
 */
final class UserEnableCommand implements CommandInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function name(): string
    {
        return 'user:enable';
    }

    public function description(): string
    {
        return 'Réactive un compte utilisateur désactivé (<email>)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $email = UserRepository::normalizeEmail(ArgParser::stringOption($parsed['options'], 'email', $parsed['positionals'][0] ?? ''));

        if ($email === '') {
            $output->error('Usage : bin/prime user:enable <email>');

            return self::FAILURE;
        }

        try {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('Utilisateur introuvable : "%s".', $email));

                return self::FAILURE;
            }

            if ($user->isActive()) {
                $output->writeln(sprintf('Le compte %s est déjà actif.', $user->email));

                return self::SUCCESS;
            }

            $this->users->updateFields($user->id, ['status' => 'active']);
        } catch (Throwable $exception) {
            $output->error('Échec de l\'activation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Compte %s réactivé avec succès.', $user->email));

        return self::SUCCESS;
    }
}
