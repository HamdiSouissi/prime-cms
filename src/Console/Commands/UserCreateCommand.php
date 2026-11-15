<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\RoleRepository;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Security\PasswordPolicy;
use Throwable;

/**
 * Creates a new user account via CLI (`bin/prime user:create`).
 */
final class UserCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
    }

    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'Crée un nouvel utilisateur (--email, --name, --role, --password)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['no-interaction', 'generate-password']);
        $opts = $parsed['options'];

        $email = UserRepository::normalizeEmail(ArgParser::stringOption($opts, 'email'));
        $displayName = trim(ArgParser::stringOption($opts, 'name', ArgParser::stringOption($opts, 'display-name')));
        $roleSpec = trim(ArgParser::stringOption($opts, 'role', 'contributor'));
        $locale = trim(ArgParser::stringOption($opts, 'locale', 'fr'));
        $status = trim(ArgParser::stringOption($opts, 'status', 'active'));
        $password = ArgParser::stringOption($opts, 'password');
        $generatePassword = ArgParser::hasFlag($opts, 'generate-password');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->error('Option --email requise avec une adresse e-mail valide.');

            return self::FAILURE;
        }

        if ($displayName === '') {
            $output->error('Option --name requise (nom affiché de l\'utilisateur).');

            return self::FAILURE;
        }

        if (!in_array($status, ['active', 'disabled'], true)) {
            $output->error('Option --status invalide : valeurs acceptées "active" ou "disabled".');

            return self::FAILURE;
        }

        $generated = false;
        if ($password === '' || $generatePassword) {
            $password = self::generateStrongPassword();
            $generated = true;
        } else {
            $violation = $this->passwordPolicy->validate($password, [
                'email' => $email,
                'display_name' => $displayName,
            ]);
            if ($violation !== null) {
                $output->error($violation);

                return self::FAILURE;
            }
        }

        try {
            if ($this->users->findByEmail($email) !== null) {
                $output->error(sprintf('Un utilisateur avec l\'adresse "%s" existe déjà.', $email));

                return self::FAILURE;
            }

            $roleSlugs = array_values(array_filter(array_map('trim', explode(',', $roleSpec)), static fn (string $s): bool => $s !== ''));
            if ($roleSlugs === []) {
                $roleSlugs = ['contributor'];
            }

            $roleIds = [];
            $resolvedRoleNames = [];
            foreach ($roleSlugs as $slug) {
                $role = $this->roles->findBySlug($slug);
                if ($role === null) {
                    $output->error(sprintf('Rôle introuvable : "%s". Exécutez "bin/prime role:list" pour voir les rôles.', $slug));

                    return self::FAILURE;
                }
                $roleIds[] = $role->id;
                $resolvedRoleNames[] = $role->name;
            }

            $user = $this->users->create(
                email: $email,
                displayName: $displayName,
                plainPassword: $password,
                roleIds: $roleIds,
                status: $status,
                locale: $locale !== '' ? $locale : 'fr',
            );
        } catch (Throwable $exception) {
            $output->error('Échec de la création de l\'utilisateur : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Utilisateur #%d "%s" <%s> créé (rôles : %s, statut : %s).',
            $user->id,
            $user->displayName,
            $user->email,
            implode(', ', $resolvedRoleNames),
            $user->status,
        ));

        if ($generated) {
            $output->writeln('Mot de passe généré (affiché une seule fois) : ' . $password);
        }

        return self::SUCCESS;
    }

    /**
     * Generates a cryptographically strong 20-character password guaranteed to pass PasswordPolicy.
     */
    public static function generateStrongPassword(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%&*?-_+=';
        $all = $upper . $lower . $digits . $symbols;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = 0; $i < 16; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
