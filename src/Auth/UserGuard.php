<?php

declare(strict_types=1);

namespace Prime\Auth;

use Prime\Auth\Exception\PolicyException;

/**
 * Server-side user and role security guard shared by BOTH web controllers and CLI commands.
 *
 * Enforces:
 * 1. Self-protection: an actor cannot delete themselves, disable themselves, or remove their own admin role.
 * 2. Last-administrator protection: the last active administrator can never be deleted, disabled, or demoted.
 * 3. Privilege-escalation prevention: a non-administrator cannot create, edit, or delete an administrator,
 *    nor grant any role containing capabilities they themselves do not possess.
 */
final class UserGuard
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
    ) {
    }

    /**
     * Assert that `$actor` (null for CLI/system) can assign `$roleIds` when creating a user.
     *
     * @param list<int> $roleIds
     * @throws PolicyException
     */
    public function assertCanAssignRoles(?User $actor, array $roleIds): void
    {
        if ($roleIds === []) {
            throw new PolicyException('Sélectionnez au moins un rôle pour cet utilisateur.');
        }

        $targetRoles = $this->roles->findByIds($roleIds);
        if ($targetRoles === []) {
            throw new PolicyException('Aucun rôle valide n\'a été sélectionné.');
        }

        if ($actor === null || $actor->isAdministrator()) {
            return;
        }

        foreach ($targetRoles as $role) {
            if ($role->isAdministrator()) {
                throw new PolicyException('Seul un administrateur peut attribuer le rôle Administrateur.');
            }

            foreach ($role->capabilities as $cap) {
                if (!$actor->can($cap)) {
                    throw new PolicyException(
                        sprintf('Vous ne pouvez pas attribuer le rôle « %s » car il contient des permissions que vous ne possédez pas.', $role->name),
                    );
                }
            }
        }
    }

    /**
     * Assert that `$actor` can edit `$target` (with optional new status and new roles).
     *
     * @param list<int>|null $newRoleIds Null when roles are not being changed
     * @throws PolicyException
     */
    public function assertCanUpdate(?User $actor, User $target, string $newStatus, ?array $newRoleIds): void
    {
        if ($actor !== null && !$actor->isAdministrator() && $target->isAdministrator()) {
            throw new PolicyException('Seul un administrateur peut modifier le compte d\'un administrateur.');
        }

        $isSelf = $actor !== null && $actor->id === $target->id;

        if ($isSelf && $newStatus !== User::STATUS_ACTIVE) {
            throw new PolicyException('Vous ne pouvez pas désactiver votre propre compte.');
        }

        if ($newRoleIds !== null) {
            $this->assertCanAssignRoles($actor, $newRoleIds);

            $newRoles = $this->roles->findByIds($newRoleIds);
            $willBeAdmin = false;
            foreach ($newRoles as $role) {
                if ($role->isAdministrator()) {
                    $willBeAdmin = true;
                    break;
                }
            }

            if ($isSelf && $target->isAdministrator() && !$willBeAdmin) {
                throw new PolicyException('Vous ne pouvez pas retirer votre propre rôle Administrateur.');
            }

            if ($target->isActive() && $target->isAdministrator() && !$willBeAdmin) {
                $this->assertNotLastActiveAdministrator('retirer le rôle Administrateur au');
            }
        }

        if ($target->isActive() && $target->isAdministrator() && $newStatus !== User::STATUS_ACTIVE) {
            $this->assertNotLastActiveAdministrator('désactiver le');
        }
    }

    /**
     * Assert that `$actor` (null for CLI) can disable `$target`.
     *
     * @throws PolicyException
     */
    public function assertCanDisable(?User $actor, User $target): void
    {
        if ($actor !== null && $actor->id === $target->id) {
            throw new PolicyException('Vous ne pouvez pas désactiver votre propre compte.');
        }

        if ($actor !== null && !$actor->isAdministrator() && $target->isAdministrator()) {
            throw new PolicyException('Seul un administrateur peut désactiver un administrateur.');
        }

        if ($target->isActive() && $target->isAdministrator()) {
            $this->assertNotLastActiveAdministrator('désactiver le');
        }
    }

    /**
     * Assert that `$actor` (null for CLI) can delete `$target`.
     *
     * @throws PolicyException
     */
    public function assertCanDelete(?User $actor, User $target): void
    {
        if ($actor !== null && $actor->id === $target->id) {
            throw new PolicyException('Vous ne pouvez pas supprimer votre propre compte.');
        }

        if ($actor !== null && !$actor->isAdministrator() && $target->isAdministrator()) {
            throw new PolicyException('Seul un administrateur peut supprimer un administrateur.');
        }

        if ($target->isActive() && $target->isAdministrator()) {
            $this->assertNotLastActiveAdministrator('supprimer le');
        }
    }

    /**
     * Assert that removing `$role` from `$target` (e.g. via CLI `user:role --remove`) is safe.
     *
     * @throws PolicyException
     */
    public function assertCanRemoveRole(?User $actor, User $target, Role $role): void
    {
        if ($actor !== null && $actor->id === $target->id && $role->isAdministrator()) {
            throw new PolicyException('Vous ne pouvez pas retirer votre propre rôle Administrateur.');
        }

        if ($actor !== null && !$actor->isAdministrator() && $target->isAdministrator()) {
            throw new PolicyException('Seul un administrateur peut modifier les rôles d\'un administrateur.');
        }

        if ($role->isAdministrator() && $target->isActive() && $target->isAdministrator()) {
            // Check if the user has another administrator role besides this one.
            $otherAdminRoles = 0;
            foreach ($target->roles as $r) {
                if ($r->id !== $role->id && $r->isAdministrator()) {
                    $otherAdminRoles++;
                }
            }

            if ($otherAdminRoles === 0) {
                $this->assertNotLastActiveAdministrator('retirer le rôle Administrateur au');
            }
        }
    }

    /**
     * Assert that `$actor` can perform an administrative security action on `$target` (2FA reset, force logout, etc.).
     *
     * @throws PolicyException
     */
    public function assertCanManageSecurity(?User $actor, User $target): void
    {
        if ($actor !== null && !$actor->isAdministrator() && $target->isAdministrator()) {
            throw new PolicyException('Seul un administrateur peut effectuer cette action sur un compte administrateur.');
        }
    }

    private function assertNotLastActiveAdministrator(string $actionPhrase): void
    {
        if ($this->users->countActiveAdministrators() <= 1) {
            throw new PolicyException(
                sprintf('Impossible de %s dernier administrateur actif du site.', $actionPhrase),
            );
        }
    }
}
