<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\TwoFactorService;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;
use Throwable;

/**
 * Emergency CLI recovery command to disable 2FA for a locked-out user (`bin/prime user:2fa:reset`).
 */
final class UserTwoFactorResetCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'user:2fa:reset';
    }

    public function description(): string
    {
        return 'Réinitialise la 2FA (TOTP) d\'un utilisateur en cas de perte d\'appareil (<email>)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $email = UserRepository::normalizeEmail(ArgParser::stringOption($parsed['options'], 'email', $parsed['positionals'][0] ?? ''));

        if ($email === '') {
            $output->error('Usage : bin/prime user:2fa:reset <email>');

            return self::FAILURE;
        }

        try {
            /** @var UserRepository $users */
            $users = $this->app->get(UserRepository::class);
            $user = $users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('Utilisateur introuvable : "%s".', $email));

                return self::FAILURE;
            }

            if (!$user->hasTwoFactor()) {
                $output->writeln(sprintf('La 2FA n\'est pas active pour %s.', $user->email));

                return self::SUCCESS;
            }

            /** @var TwoFactorService $twoFactor */
            $twoFactor = $this->app->get(TwoFactorService::class);
            $twoFactor->disable($user, null);
        } catch (Throwable $exception) {
            $output->error('Échec de la réinitialisation 2FA : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'La 2FA a été réinitialisée pour %s et toutes ses sessions actives ont été révoquées.',
            $user->email,
        ));

        return self::SUCCESS;
    }
}
