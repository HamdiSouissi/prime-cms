<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Prime\Auth\Role;
use Prime\Auth\TwoFactorPolicy;
use Prime\Auth\User;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Who has to enrol in double-factor authentication, and when nobody does.
 *
 * The master switch is opt-in on purpose: an administrator locked out of the only admin account is
 * the one failure a CMS cannot click its way out of, so a fresh install must never demand a device.
 */
final class TwoFactorPolicyTest extends TestCase
{
    public function testAFreshInstallDemandsNothingFromAnybody(): void
    {
        $policy = new TwoFactorPolicy($this->settings());

        $this->assertFalse($policy->isEnabled(), 'the master switch defaults to off');
        $this->assertFalse($policy->isRequired($this->user('administrator')));
        $this->assertTrue($policy->allowsAccess($this->user('administrator')));

        // The role list is pre-filled so turning the switch on has an effect immediately.
        $this->assertSame(['administrator'], $policy->requiredRoles());
    }

    public function testAnUnreachableSettingsStoreAlsoMeansDisabled(): void
    {
        $policy = new TwoFactorPolicy();

        $this->assertFalse($policy->isEnabled());
        $this->assertSame([], $policy->requiredRoles());
        $this->assertFalse($policy->isRequired($this->user('administrator')));
    }

    public function testEnrolmentIsRequiredForListedRolesOnly(): void
    {
        $settings = $this->settings();
        $settings->set(TwoFactorPolicy::SETTING_ENABLED, '1');

        $policy = new TwoFactorPolicy($settings);

        $this->assertTrue($policy->isEnabled());
        $this->assertTrue($policy->isRequired($this->user('administrator')));
        $this->assertFalse($policy->isRequired($this->user('editor')));
        $this->assertFalse($policy->allowsAccess($this->user('administrator')));
    }

    public function testAnAccountThatAlreadyHasTwoFactorIsNeverHeldBack(): void
    {
        $settings = $this->settings();
        $settings->set(TwoFactorPolicy::SETTING_ENABLED, true);

        $policy = new TwoFactorPolicy($settings);
        $enrolled = $this->user('administrator', '2026-11-15 10:00:00');

        $this->assertFalse($policy->isRequired($enrolled));
        $this->assertTrue($policy->allowsAccess($enrolled));
    }

    public function testTheIssuerFallsBackToTheProductName(): void
    {
        $settings = $this->settings();
        $settings->set(TwoFactorPolicy::SETTING_ISSUER, '   ');

        $this->assertSame('Prime CMS', (new TwoFactorPolicy($settings))->issuer());

        $settings->set(TwoFactorPolicy::SETTING_ISSUER, ' Atelier Lumière ');
        $this->assertSame('Atelier Lumière', (new TwoFactorPolicy($settings))->issuer());
    }

    public function testRolesCanBeWidenedAndEmptiedByAnAdministrator(): void
    {
        $settings = $this->settings();
        $settings->set(TwoFactorPolicy::SETTING_ENABLED, '1');
        $settings->set(TwoFactorPolicy::SETTING_ROLES, ['administrator', 'editor']);

        $policy = new TwoFactorPolicy($settings);
        $this->assertSame(['administrator', 'editor'], $policy->requiredRoles());
        $this->assertTrue($policy->isRequired($this->user('editor')));

        $settings->set(TwoFactorPolicy::SETTING_ROLES, []);
        $emptied = new TwoFactorPolicy($settings);

        // Nothing listed means nobody is forced, even with the switch on.
        $this->assertSame([], $emptied->requiredRoles());
        $this->assertFalse($emptied->isRequired($this->user('administrator')));
    }

    private function user(string $roleSlug, ?string $confirmedAt = null): User
    {
        $role = new Role(1, $roleSlug, ucfirst($roleSlug), null, true, ['*']);

        return new User(
            id: 1,
            email: $roleSlug . '@example.fr',
            displayName: 'Utilisateur ' . $roleSlug,
            status: User::STATUS_ACTIVE,
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: $confirmedAt,
            twoFactorLastStep: null,
            createdAt: null,
            updatedAt: null,
            roles: [$role],
            capabilities: ['*'],
        );
    }

    private function settings(): Settings
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        return new Settings(new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $pdo));
    }
}
