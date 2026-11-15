<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Prime\Auth\Capabilities;
use Prime\Auth\Middleware\Authenticate;
use Prime\Auth\Role;
use Prime\Auth\User;
use Prime\Hooks\Hooks;

final class CapabilitiesAndAuthUnitTest extends TestCase
{
    public function testCapabilitiesRegistryAndFilterHook(): void
    {
        $hooks = new Hooks();
        $hooks->filter('auth.capabilities', static function (array $caps): array {
            $caps['shop.manage'] = [
                'label' => 'Gérer la boutique',
                'group' => 'Boutique',
            ];

            return $caps;
        });

        $registry = new Capabilities($hooks);
        $all = $registry->all();

        $this->assertArrayHasKey('dashboard.view', $all);
        $this->assertArrayHasKey('users.manage_roles', $all);
        $this->assertArrayHasKey('roles.manage', $all);
        $this->assertArrayHasKey('content.publish', $all);
        $this->assertArrayHasKey('settings.manage', $all);
        $this->assertArrayHasKey('shop.manage', $all);

        // `filterValid` rejects unknown keys and never allows wildcard `*` from arbitrary forms.
        $filtered = $registry->filterValid(['users.view', '*', 'fake.cap', 'shop.manage', 'users.view']);
        $this->assertSame(['users.view', 'shop.manage'], $filtered);

        $grouped = $registry->grouped();
        $this->assertArrayHasKey('Utilisateurs', $grouped);
        $this->assertArrayHasKey('Boutique', $grouped);
    }

    public function testUserCapabilityResolutionMultiRoleWildcardAndDisabledAccount(): void
    {
        $editor = new Role(2, 'editor', 'Éditeur', null, true, ['dashboard.view', 'content.publish']);
        $mediaMgr = new Role(5, 'media', 'Médiathèque', null, false, ['media.view', 'media.delete_any']);

        // Multi-role union
        $multiUser = new User(
            id: 10,
            email: 'claire@example.fr',
            displayName: 'Claire Moreau',
            status: User::STATUS_ACTIVE,
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: null,
            updatedAt: null,
            roles: [$editor, $mediaMgr],
            capabilities: ['dashboard.view', 'content.publish', 'media.view', 'media.delete_any'],
        );

        $this->assertTrue($multiUser->can('content.publish'));
        $this->assertTrue($multiUser->can('media.delete_any'));
        $this->assertFalse($multiUser->can('users.delete'));
        $this->assertFalse($multiUser->isAdministrator());
        $this->assertSame('CM', $multiUser->initials());
        $this->assertSame('Éditeur', $multiUser->primaryRoleName());

        // Administrator wildcard
        $adminRole = new Role(1, 'administrator', 'Administrateur', null, true, ['*']);
        $admin = new User(
            id: 1,
            email: 'admin@example.fr',
            displayName: 'Camille Laurent',
            status: User::STATUS_ACTIVE,
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: '2026-11-15 10:00:00',
            twoFactorLastStep: 123,
            createdAt: null,
            updatedAt: null,
            roles: [$adminRole],
            capabilities: ['*'],
        );

        $this->assertTrue($admin->isAdministrator());
        $this->assertTrue($admin->can('anything.at.all'));
        $this->assertTrue($admin->hasTwoFactor());

        // Disabled administrator has zero active capabilities
        $disabledAdmin = new User(
            id: 2,
            email: 'disabled@example.fr',
            displayName: 'Ex Admin',
            status: User::STATUS_DISABLED,
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: null,
            updatedAt: null,
            roles: [$adminRole],
            capabilities: ['*'],
        );

        $this->assertFalse($disabledAdmin->isActive());
        $this->assertFalse($disabledAdmin->can('dashboard.view'));
        $this->assertFalse($disabledAdmin->can('users.view'));
    }

    public function testSafeAdminRedirectPreventsOpenRedirectsAndLoops(): void
    {
        // Valid internal admin targets
        $this->assertTrue(Authenticate::isSafeAdminRedirect('/admin'));
        $this->assertTrue(Authenticate::isSafeAdminRedirect('/admin/users'));
        $this->assertTrue(Authenticate::isSafeAdminRedirect('/admin/users?search=camille&page=2'));
        $this->assertTrue(Authenticate::isSafeAdminRedirect('/admin/roles/4/edit'));

        // Open-redirect and malformed attacks strictly rejected
        $this->assertFalse(Authenticate::isSafeAdminRedirect(null));
        $this->assertFalse(Authenticate::isSafeAdminRedirect(''));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('https://evil.example/admin'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('http://evil.example'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('//evil.example/admin'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/\\evil.example/admin'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/../etc/passwd'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect("/admin\r\nLocation: https://evil.example"));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/administrator'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/'));

        // Auth loop endpoints rejected
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/login'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/login/two-factor'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/logout'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/forgot-password'));
        $this->assertFalse(Authenticate::isSafeAdminRedirect('/admin/reset-password/abc123'));
    }
}
