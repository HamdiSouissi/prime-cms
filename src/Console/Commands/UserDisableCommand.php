<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\Exception\PolicyException;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Throwable;

/**
 * Disables a user account and immediately revokes all active sessions (`bin/prime user:disable`).
 */
final class UserDisableCommand implements CommandInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserGuard $guard,
    ) {
    }

    public function name(): string
    {
        return 'user:disable';
    }

    public function description(): string
    {
        return 'Désactive un compte utilisateur et révoque ses sessions (<email>)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $email = UserRepository::normalizeEmail(ArgParser::stringOption($parsed['options'], 'email', $parsed['positionals'][0] ?? ''));

        if ($email === '') {
            $output->error('Usage : bin/prime user:disable <email>');

            return self::FAILURE;
        }

        try {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('Utilisateur introuvable : "%s".', $email));

                return self::FAILURE;
            }

            if (!$user->isActive()) {
                $output->writeln(sprintf('Le compte %s est déjà désactivé.', $user->email));

                return self::SUCCESS;
            }

            $this->guard->assertCanDisable(null, $user);
            $this->users->updateFields($user->id, ['status' => 'disabled']);
            $this->users->incrementSessionVersionAndRevokeTokens($user->id);
        } catch (PolicyException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $output->error('Échec de la désactivation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Compte %s désactivé et toutes ses sessions ont été révoquées.', $user->email));

        return self::SUCCESS;
    }
}
