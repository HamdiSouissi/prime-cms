<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Notice;
use Prime\Admin\Notices;
use Prime\Auth\Role;
use Prime\Auth\User;
use Prime\Database\Connection;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class NoticesTest extends TestCase
{
    private Settings $settings;
    private Notices $notices;

    protected function setUp(): void
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $this->settings = new Settings($conn);
        $this->notices = new Notices($this->settings);
    }

    public function testAddScopeFilterDismissAndRemoveBySource(): void
    {
        $admin = $this->makeUser(1, ['*']);
        $editor = $this->makeUser(2, ['content.view']);
        $pluginMgr = $this->makeUser(3, ['plugins.manage']);

        $this->notices->add(new Notice(
            id: 'plugin.welcome.hello-prime',
            type: Notice::TYPE_INFO,
            title: 'Hello Prime activé',
            message: 'Bienvenue !',
            actionLabel: 'Configurer',
            actionUrl: '/admin/hello-prime',
            capability: null, // admins only
            dismissible: true,
            source: 'hello-prime',
        ));

        $this->notices->add(new Notice(
            id: 'plugin.error.broken',
            type: Notice::TYPE_DANGER,
            title: 'Plugin en erreur',
            message: 'Échec au chargement.',
            actionLabel: 'Réessayer',
            actionUrl: '/admin/plugins/broken/activate',
            actionMethod: 'POST',
            capability: 'plugins.manage',
            dismissible: false,
            source: 'broken',
        ));

        // Admin sees both; plugin manager sees only plugins.manage notice; editor sees none
        $this->assertCount(2, $this->notices->forUser($admin));
        $this->assertCount(1, $this->notices->forUser($pluginMgr));
        $this->assertSame('plugin.error.broken', $this->notices->forUser($pluginMgr)[0]->id);
        $this->assertCount(0, $this->notices->forUser($editor));

        // Dismiss dismissible notice for admin -> only non-dismissible remains for admin
        $this->assertTrue($this->notices->dismiss('plugin.welcome.hello-prime', $admin->id));
        $this->assertCount(1, $this->notices->forUser($admin));

        // Non-dismissible notice cannot be dismissed by user
        $this->assertFalse($this->notices->dismiss('plugin.error.broken', $admin->id));
        $this->assertCount(1, $this->notices->forUser($admin));

        // Remove by source cleans up plugin notices
        $this->assertSame(1, $this->notices->removeBySource('broken'));
        $this->assertCount(0, $this->notices->forUser($admin));
        $this->assertCount(0, $this->notices->forUser($pluginMgr));
    }

    /**
     * @param list<string> $capabilities
     */
    private function makeUser(int $id, array $capabilities): User
    {
        $role = new Role(
            id: $id,
            slug: $capabilities === ['*'] ? 'administrator' : 'custom',
            name: 'Role',
            description: null,
            isSystem: $capabilities === ['*'],
            capabilities: $capabilities,
        );

        return new User(
            id: $id,
            email: "user{$id}@prime.local",
            displayName: "User {$id}",
            status: User::STATUS_ACTIVE,
            locale: 'fr',
            lastLoginAt: null,
            sessionVersion: 1,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            passwordChangedAt: null,
            createdAt: '2026-11-15 00:00:00',
            updatedAt: '2026-11-15 00:00:00',
            roles: [$role],
            capabilities: $capabilities,
        );
    }
}
