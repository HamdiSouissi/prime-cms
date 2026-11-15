<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Auth\Exception\PolicyException;
use Prime\Auth\RoleRepository;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Throwable;

/**
 * Assigns, adds, or removes roles on a user (`bin/prime user:role`).
 */
final class UserRoleCommand implements CommandInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserGuard $guard,
    ) {
    }

    public function name(): string
    {
        return 'user:role';
    }

    public function description(): string
    {
        return 'Modifie les rôles d\'un utilisateur (<email> <role[,role2]> [--add|--remove])';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['add', 'remove']);
        $opts = $parsed['options'];
        $pos = $parsed['positionals'];

        $email = UserRepository::normalizeEmail(ArgParser::stringOption($opts, 'email', $pos[0] ?? ''));
        $roleSpec = trim(ArgParser::stringOption($opts, 'role', $pos[1] ?? ''));
        $isAdd = ArgParser::hasFlag($opts, 'add');
        $isRemove = ArgParser::hasFlag($opts, 'remove');

        if ($email === '' || $roleSpec === '') {
            $output->error('Usage : bin/prime user:role <email> <role[,role2]> [--add|--remove]');

            return self::FAILURE;
        }

        if ($isAdd && $isRemove) {
            $output->error('Les options --add et --remove sont mutuellement exclusives.');

            return self::FAILURE;
        }

        try {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('Utilisateur introuvable : "%s".', $email));

                return self::FAILURE;
            }

            $slugs = array_values(array_filter(array_map('trim', explode(',', $roleSpec)), static fn (string $s): bool => $s !== ''));
            $targetRoleIds = [];
            foreach ($slugs as $slug) {
                $role = $this->roles->findBySlug($slug);
                if ($role === null) {
                    $output->error(sprintf('Rôle introuvable : "%s".', $slug));

                    return self::FAILURE;
                }
                $targetRoleIds[] = $role->id;
            }

            $currentIds = array_map(static fn ($r): int => $r->id, $user->roles);
            if ($isAdd) {
                $newRoleIds = array_values(array_unique(array_merge($currentIds, $targetRoleIds)));
            } elseif ($isRemove) {
                $newRoleIds = array_values(array_diff($currentIds, $targetRoleIds));
            } else {
                $newRoleIds = array_values(array_unique($targetRoleIds));
            }

            if ($newRoleIds === []) {
                $output->error('Un utilisateur doit conserver au moins un rôle.');

                return self::FAILURE;
            }

            // CLI acts as super-operator (actor = null), still enforcing the last-active-administrator invariant!
            $this->guard->assertCanUpdate(null, $user, $user->status, $newRoleIds);
            $this->users->syncRoles($user->id, $newRoleIds);

            $updated = $this->users->find($user->id);
            $finalRoles = $updated !== null
                ? implode(', ', array_map(static fn ($r): string => $r->slug, $updated->roles))
                : '';
        } catch (PolicyException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $output->error('Échec de la modification des rôles : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Rôles mis à jour pour %s : %s.', $user->email, $finalRoles));

        return self::SUCCESS;
    }
}
