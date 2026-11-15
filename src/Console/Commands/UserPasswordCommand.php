<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Security\PasswordPolicy;
use Throwable;

/**
 * Resets a user's password and revokes all active sessions (`bin/prime user:password`).
 */
final class UserPasswordCommand implements CommandInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
    }

    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return 'Définit un nouveau mot de passe pour un utilisateur (<email> [--password=...|--generate])';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['generate', 'no-interaction']);
        $opts = $parsed['options'];
        $pos = $parsed['positionals'];

        $email = UserRepository::normalizeEmail(ArgParser::stringOption($opts, 'email', $pos[0] ?? ''));
        if ($email === '') {
            $output->error('Adresse e-mail requise : bin/prime user:password <email> [--password=...]');

            return self::FAILURE;
        }

        $password = ArgParser::stringOption($opts, 'password');
        $generate = ArgParser::hasFlag($opts, 'generate') || $password === '';

        try {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('Utilisateur introuvable : "%s".', $email));

                return self::FAILURE;
            }

            if ($generate) {
                $password = UserCreateCommand::generateStrongPassword();
            } else {
                $violation = $this->passwordPolicy->validate($password, [
                    'email' => $user->email,
                    'display_name' => $user->displayName,
                ]);
                if ($violation !== null) {
                    $output->error($violation);

                    return self::FAILURE;
                }
            }

            $this->users->updatePassword($user->id, $password);
        } catch (Throwable $exception) {
            $output->error('Échec de la mise à jour du mot de passe : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Mot de passe mis à jour pour %s (toutes les sessions actives ont été révoquées).',
            $user->email,
        ));

        if ($generate) {
            $output->writeln('Nouveau mot de passe généré (affiché une seule fois) : ' . $password);
        }

        return self::SUCCESS;
    }
}
