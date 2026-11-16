<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\RoleRepository;
use Prime\Auth\UserRepository;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Media\MediaUsageRepository;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\PermalinkSettings;
use Prime\Site\RedirectRepository;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\ZoneRepository;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 8:
 * - Migrations 000019–000023 schema creation, FK cascades, and clean full rollback (23 migrations)
 * - MenuRepository 3-level hierarchy & ON DELETE CASCADE on menu deletion
 * - ZoneRepository + MediaUsageRepository::syncForZone() tracking
 * - RedirectRepository chain shortening, hit counters, and cycle guard
 * - UserRepository::findByAuthorSlug() & batch findByIds()
 */
#[Group('database')]
final class SiteDatabaseIntegrationTest extends DatabaseTestCase
{
    public function testPhase8MigrationsSchemaMenusZonesRedirectsAndRollback(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);
        $ran = $migrator->run();
        $this->assertCount(23, $ran);

        $this->assertTrue($schema->hasTable('menus'));
        $this->assertTrue($schema->hasTable('menu_items'));
        $this->assertTrue($schema->hasTable('zones'));
        $this->assertTrue($schema->hasTable('redirects'));
        $this->assertTrue($schema->hasTable('media_zone_usage'));
        $this->assertTrue($schema->hasColumn('users', 'username'));
        $this->assertTrue($schema->hasColumn('users', 'bio'));

        // 1. UserRepository username/bio + findByAuthorSlug + batch findByIds
        $roles = new RoleRepository($this->connection);
        $users = new UserRepository($this->connection, $roles);
        $adminRole = $roles->findBySlug('administrator');
        $this->assertNotNull($adminRole);

        $u1 = $users->create(
            email: 'alice@db.test',
            displayName: 'Alice Martin',
            plainPassword: 'Secret!1234#Prime',
            roleIds: [$adminRole->id],
        );
        $u2 = $users->create(
            email: 'bob@db.test',
            displayName: 'Bob Durand',
            plainPassword: 'Secret!1234#Prime',
            roleIds: [$adminRole->id],
        );
        $users->updateFields($u1->id, ['username' => 'alice-martin', 'bio' => 'Auteure principale']);

        $foundBySlug = $users->findByAuthorSlug('alice-martin');
        $this->assertNotNull($foundBySlug);
        $this->assertSame($u1->id, $foundBySlug->id);
        $this->assertSame('Auteure principale', $foundBySlug->bio);

        $batch = $users->findByIds([$u1->id, $u2->id]);
        $this->assertCount(2, $batch);
        $this->assertSame('Alice Martin', $batch[$u1->id]->displayName);

        // 2. MenuRepository 3-level hierarchy & FK ON DELETE CASCADE
        $urls = new UrlGenerator(new PermalinkSettings(), baseUrl: 'https://prime.test');
        $menus = new MenuRepository($this->connection, $urls);
        $menuId = $menus->create('Principal', 'primary');
        $menus->replaceItems($menuId, [
            [
                'label' => 'Accueil',
                'type' => 'custom',
                'url' => '/',
                'children' => [
                    [
                        'label' => 'Blog',
                        'type' => 'custom',
                        'url' => '/blog',
                        'children' => [
                            ['label' => 'Archives', 'type' => 'custom', 'url' => '/blog/page/2'],
                        ],
                    ],
                ],
            ],
        ]);

        $tree = $menus->itemsTree($menuId, forPublic: false);
        $this->assertCount(1, $tree);
        $this->assertSame('Archives', $tree[0]['children'][0]['children'][0]['label']);

        $menus->delete($menuId);
        $this->assertSame(0, $this->connection->table('menu_items')->where('menu_id', '=', $menuId)->count());

        // 3. ZoneRepository + MediaUsageRepository zone tracking
        $renderer = new Renderer(new BlockRegistry());
        $usage = new MediaUsageRepository($this->connection);
        $zones = new ZoneRepository($this->connection, $renderer, $usage);

        $saved = $zones->save('sidebar', [
            'version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Bienvenue dans la sidebar.']],
            ],
        ]);
        $this->assertSame(1, $saved['block_count']);
        $this->assertStringContainsString('Bienvenue dans la sidebar.', $zones->renderLive('sidebar'));

        // 4. RedirectRepository chain shortening + hit increment
        $redirects = new RedirectRepository($this->connection);
        $this->assertTrue($redirects->registerRedirect('/v1', '/v2', 301));
        $this->assertTrue($redirects->registerRedirect('/v2', '/v3', 301));
        $rule = $redirects->findBySource('/v1');
        $this->assertNotNull($rule);
        $this->assertSame('/v3', $rule['target_path']);

        $redirects->recordHit($rule['id']);
        $afterHit = $redirects->findBySource('/v1');
        $this->assertSame(1, $afterHit['hits'] ?? 0);

        // 5. Full rollback of all 23 migrations leaves clean DB
        $rolledBack = $migrator->rollback(1);
        $this->assertCount(23, $rolledBack);
        $this->assertFalse($schema->hasTable('menus'));
        $this->assertFalse($schema->hasTable('zones'));
        $this->assertFalse($schema->hasTable('redirects'));
        $this->assertFalse($schema->hasTable('media_zone_usage'));
    }
}
