<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Auth\RoleRepository;
use Prime\Auth\UserRepository;
use Prime\Cache\PageCache;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Editor\BlockRegistry;
use Prime\Editor\Renderer;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Media\MediaUsageRepository;
use Prime\Security\Csrf;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\PermalinkSettings;
use Prime\Site\RedirectRepository;
use Prime\Site\Seo\SeoSettings;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeOptions;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 8:
 * - Migrations up to 000023: schema creation, FK cascades, and a clean full rollback
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
        $this->assertCount($this->coreMigrationCount(), $ran);

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
        $renderer = new Renderer(new BlockRegistry(), new HtmlSanitizer());
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

        // 5. Full rollback of every migration leaves a clean DB
        $rolledBack = $migrator->rollback(1);
        $this->assertCount($this->coreMigrationCount(), $rolledBack);
        $this->assertFalse($schema->hasTable('menus'));
        $this->assertFalse($schema->hasTable('zones'));
        $this->assertFalse($schema->hasTable('redirects'));
        $this->assertFalse($schema->hasTable('media_zone_usage'));
    }

    public function testFullHttpSiteContextsRedirectsMenusZonesSeoPermissionsAndZeroNPlusOneOnRealDb(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']);
        $this->assertCount($this->coreMigrationCount(), $migrator->run());

        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.url', 'https://prime-db.test');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('d', 32)));
        // The page cache is covered by its own suite: here `/blog` must always reach the
        // database so the SQL query counts measure template efficiency, not cache hits.
        $app->config()->set('cache.page.enabled', false);
        $app->config()->set('cache.page.directory', sys_get_temp_dir() . '/prime_pages_' . uniqid('', true));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->container()->instance(Connection::class, $this->connection);

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var RoleRepository $roles */
        $roles = $app->get(RoleRepository::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var Session $session */
        $session = $app->get(Session::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var TermRepository $terms */
        $terms = $app->get(TermRepository::class);
        /** @var MenuRepository $menus */
        $menus = $app->get(MenuRepository::class);
        /** @var ZoneRepository $zones */
        $zones = $app->get(ZoneRepository::class);
        /** @var PermalinkSettings $permalinks */
        $permalinks = $app->get(PermalinkSettings::class);
        /** @var SeoSettings $seoSettings */
        $seoSettings = $app->get(SeoSettings::class);
        /** @var ThemeManager $themes */
        $themes = $app->get(ThemeManager::class);
        /** @var ThemeOptions $themeOptions */
        $themeOptions = $app->get(ThemeOptions::class);
        /** @var Notices $notices */
        $notices = $app->get(Notices::class);
        /** @var Settings $settings */
        $settings = $app->get(Settings::class);

        $adminRole = $roles->findBySlug('administrator');
        $contribRole = $roles->findBySlug('contributor');
        $this->assertNotNull($adminRole);
        $this->assertNotNull($contribRole);

        $admin = $users->create('admin@db.test', 'Admin DB', 'Secret!1234#Prime', [$adminRole->id]);
        $users->updateFields($admin->id, ['username' => 'admin-db', 'bio' => 'Éditeur principal DB']);
        $contrib1 = $users->create('c1@db.test', 'Contrib Un', 'Secret!1234#Prime', [$contribRole->id]);
        $contrib2 = $users->create('c2@db.test', 'Contrib Deux', 'Secret!1234#Prime', [$contribRole->id]);
        $admin = $users->find($admin->id) ?? $admin;
        $contrib1 = $users->find($contrib1->id) ?? $contrib1;
        $contrib2 = $users->find($contrib2->id) ?? $contrib2;

        // Seed media for featured image + gallery sitemap test
        $now = '2026-11-15 10:00:00';
        $mediaId1 = $this->connection->table('media')->insertGetId([
            'disk_path' => '2026/11/db-hero.jpg',
            'filename' => 'db-hero.jpg',
            'original_name' => 'db-hero.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'size_bytes' => 2048,
            'width' => 1200,
            'height' => 800,
            'checksum' => str_repeat('a', 64),
            'alt' => 'Hero DB',
            'title' => 'Hero DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mediaId2 = $this->connection->table('media')->insertGetId([
            'disk_path' => '2026/11/db-gal.jpg',
            'filename' => 'db-gal.jpg',
            'original_name' => 'db-gal.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'size_bytes' => 1800,
            'width' => 800,
            'height' => 600,
            'checksum' => str_repeat('b', 64),
            'alt' => 'Galerie DB',
            'title' => 'Galerie DB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Categories & entries
        $cat = $terms->create('category', 'Actualités', 'actualites');
        $post = $entries->create('post', [
            'title' => 'Article Principal DB',
            'slug' => 'article-principal-db',
            'excerpt' => 'Résumé complet DB.',
            'content' => (string) json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Contenu principal en base réelle.']],
                    ['id' => 'g1', 'type' => 'gallery', 'data' => ['columns' => 2, 'items' => [
                        ['mediaId' => $mediaId2, 'url' => '/uploads/2026/11/db-gal.jpg', 'alt' => 'Galerie DB'],
                    ]]],
                    ['id' => 'd1', 'type' => 'dynamic', 'data' => ['name' => 'term_list', 'attrs' => ['title' => 'Catégories live', 'taxonomy' => 'category', 'show_count' => true]]],
                ],
            ]),
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 10:00:00',
            'terms' => [$cat->id],
            'featured_media_id' => $mediaId1,
            'seo' => [
                'title' => 'Titre SEO DB',
                'description' => 'Description SEO DB.',
                'canonical' => 'https://prime-db.test/blog/article-principal-db',
            ],
        ], $admin);

        $noindexPost = $entries->create('post', [
            'title' => 'Article Noindex DB',
            'slug' => 'article-noindex-db',
            'content' => '{"version":1,"blocks":[{"id":"n1","type":"paragraph","data":{"text":"Noindex."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-09 10:00:00',
            'seo' => ['noindex' => true],
        ], $admin);

        $draftPost = $entries->create('post', [
            'title' => 'Brouillon C1 DB',
            'slug' => 'brouillon-c1-db',
            'content' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Draft C1."}}]}',
            'status' => Entry::STATUS_DRAFT,
        ], $contrib1);

        $scheduledPost = $entries->create('post', [
            'title' => 'Planifié DB',
            'slug' => 'planifie-db',
            'content' => '{"version":1,"blocks":[{"id":"s1","type":"paragraph","data":{"text":"Future DB."}}]}',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2099-05-01 00:00:00',
        ], $admin);

        $trashedPost = $entries->create('post', [
            'title' => 'Corbeille DB',
            'slug' => 'corbeille-db',
            'content' => '{"version":1,"blocks":[{"id":"tr1","type":"paragraph","data":{"text":"Trashed DB."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-01 10:00:00',
        ], $admin);
        $entries->trash($trashedPost->id, $admin);
        $this->assertNotNull($scheduledPost->id);

        // 1. Public contexts + visibility + Aurora assets & options CSS
        $aurora = $themes->active();
        $this->assertNotNull($aurora->manifest);
        $savedOpts = $themeOptions->save(
            $aurora->manifest,
            array_merge($themeOptions->all($aurora->manifest), ['accent_color' => '#2563EB']),
        );
        $this->assertNotNull($savedOpts['css_url']);

        $homeRes = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $homeRes->status());
        $this->assertMatchesRegularExpression('#<script\s+src="/assets/themes/aurora/js/aurora\.js(?:\?v=[^"]*)?"\s+defer></script>#', $homeRes->body());
        $this->assertStringContainsString($savedOpts['css_url'], $homeRes->body());

        $singleRes = $kernel->handle(Request::create('GET', '/blog/article-principal-db'));
        $this->assertSame(200, $singleRes->status());
        $this->assertStringContainsString('<title>Titre SEO DB</title>', $singleRes->body());
        $this->assertStringContainsString('Description SEO DB.', $singleRes->body());
        $this->assertStringContainsString('application/ld+json', $singleRes->body());
        $this->assertStringContainsString('Catégories live', $singleRes->body());

        $noindexRes = $kernel->handle(Request::create('GET', '/blog/' . $noindexPost->slug));
        $this->assertSame(200, $noindexRes->status());
        $this->assertStringContainsString('noindex', $noindexRes->body());

        $this->assertSame(200, $kernel->handle(Request::create('GET', '/categorie/actualites'))->status());
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/auteur/admin-db'))->status());
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/recherche', ['q' => 'Principal']))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/page-inexistante'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/brouillon-c1-db'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/planifie-db'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/corbeille-db'))->status());

        // 2. Preview RBAC & settings/themes permissions
        $auth->login($contrib1);
        $this->assertSame(200, $kernel->handle(Request::create('GET', $draftPost->previewUrl()))->status());
        $auth->login($contrib2);
        $this->assertSame(403, $kernel->handle(Request::create('GET', $draftPost->previewUrl()))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/appearance/themes'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/settings/seo'))->status());
        $auth->login($admin);

        // 3. Slug rename chain A -> B -> C & static front page redirect
        $chain = $entries->create('post', [
            'title' => 'Chain DB',
            'slug' => 'db-a',
            'content' => '{"version":1,"blocks":[{"id":"ch1","type":"paragraph","data":{"text":"Chain."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-08 10:00:00',
        ], $admin);
        $chain = $entries->update($chain->id, ['slug' => 'db-b'], $admin);
        $chain = $entries->update($chain->id, ['slug' => 'db-c'], $admin);

        $redirA = $kernel->handle(Request::create('GET', '/blog/db-a'));
        $this->assertSame(301, $redirA->status());
        $this->assertSame('/blog/db-c', $redirA->header('Location'));

        $frontPage = $entries->create('page', [
            'title' => 'Vitrine DB',
            'slug' => 'vitrine-db',
            'content' => '{"version":1,"blocks":[{"id":"v1","type":"paragraph","data":{"text":"Page vitrine."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
        ], $admin);
        $permalinks->saveReading(['front_mode' => PermalinkSettings::FRONT_MODE_PAGE, 'front_page_id' => $frontPage->id]);
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/'))->status());
        $oldFront = $kernel->handle(Request::create('GET', '/vitrine-db'));
        $this->assertSame(301, $oldFront->status());
        $this->assertSame('/', $oldFront->header('Location'));
        $permalinks->saveReading(['front_mode' => PermalinkSettings::FRONT_MODE_POSTS, 'posts_per_page' => 50]);

        // 4. Reserved slug rejected
        try {
            $entries->create('page', [
                'title' => 'Blog Collision',
                'slug' => 'blog',
                'content' => '{"version":1,"blocks":[{"id":"bc1","type":"paragraph","data":{"text":"X"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
            ], $admin);
            $this->fail('Expected reserved slug "blog" to fail validation.');
        } catch (ContentValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        // 5. Menus (javascript: rejected by POST, nested aria-current, unpublished hidden) & Zones (static + dynamic)
        $menuId = $menus->create('Principal DB', 'primary');
        $csrf = (new Csrf($session))->token();
        $badMenu = $kernel->handle(Request::create(
            'POST',
            '/admin/appearance/menus/' . $menuId . '/save',
            body: [
                '_csrf_token' => $csrf,
                'name' => 'Principal DB',
                'location' => 'primary',
                'items_json' => (string) json_encode([['label' => 'Bad', 'type' => 'custom', 'url' => 'javascript:alert(1)']]),
            ],
            headers: ['Accept' => 'application/json'],
        ));
        $this->assertSame(422, $badMenu->status());

        $menus->replaceItems($menuId, [
            [
                'label' => 'Accueil',
                'type' => 'custom',
                'url' => '/',
                'children' => [
                    ['label' => 'Article Principal', 'type' => 'entry', 'target_id' => $post->id],
                ],
            ],
            ['label' => 'Brouillon Masqué', 'type' => 'entry', 'target_id' => $draftPost->id],
        ]);
        $zones->save('sidebar', [
            'version' => 1,
            'blocks' => [
                ['id' => 'st1', 'type' => 'paragraph', 'data' => ['text' => 'Texte statique DB.']],
                ['id' => 'dy1', 'type' => 'dynamic', 'data' => ['name' => 'search_form', 'attrs' => []]],
            ],
        ]);

        $postWithChrome = $kernel->handle(Request::create('GET', '/blog/article-principal-db'));
        $this->assertSame(200, $postWithChrome->status());
        $this->assertStringContainsString('aria-current="page"', $postWithChrome->body());
        $this->assertStringNotContainsString('Brouillon Masqué', $postWithChrome->body());
        $this->assertStringContainsString('Texte statique DB.', $postWithChrome->body());
        $this->assertStringContainsString('role="search"', $postWithChrome->body());

        // 6. Sitemap XML (with images + exclusions) & robots.txt (normal + no_index)
        $sitemapPost = $kernel->handle(Request::create('GET', '/sitemap-post-1.xml'));
        $this->assertSame(200, $sitemapPost->status());
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $sitemapPost->body());
        $this->assertStringContainsString('db-hero.jpg', $sitemapPost->body());
        $this->assertStringContainsString('db-gal.jpg', $sitemapPost->body());
        $this->assertStringNotContainsString('article-noindex-db', $sitemapPost->body());
        $this->assertStringNotContainsString('brouillon-c1-db', $sitemapPost->body());

        $robotsNormal = $kernel->handle(Request::create('GET', '/robots.txt'));
        $this->assertSame(200, $robotsNormal->status());
        $this->assertStringContainsString('Sitemap: https://prime-db.test/sitemap.xml', $robotsNormal->body());
        $seoSettings->save(['global_noindex' => true]);
        $robotsNoindex = $kernel->handle(Request::create('GET', '/robots.txt'));
        $this->assertStringContainsString("Disallow: /\n", $robotsNoindex->body());
        $seoSettings->save(['global_noindex' => false]);

        // 7. Zero N+1 on real MySQL/MariaDB: constant SQL query count for 10 vs 30 posts!
        for ($i = 1; $i <= 10; $i++) {
            $entries->create('post', [
                'title' => 'DB Perf ' . $i,
                'slug' => 'db-perf-' . $i,
                'author_id' => $i % 2 === 0 ? $admin->id : $contrib1->id,
                'featured_media_id' => $mediaId1,
                'content' => '{"version":1,"blocks":[{"id":"m' . $i . '","type":"paragraph","data":{"text":"Row ' . $i . '"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
                'published_at' => sprintf('2026-10-%02d 10:00:00', $i),
            ], $admin);
        }

        // The page cache would answer one of the two requests from disk without touching MySQL, and
        // `0 === 0` proves nothing about N+1: pin it off for the measurement, then restore it.
        $pageCache = $app->get(PageCache::class);
        $this->assertInstanceOf(PageCache::class, $pageCache);
        $cacheWasEnabled = $pageCache->isEnabled();
        $pageCache->setEnabled(false);

        $kernel->handle(Request::create('GET', '/blog'));
        $this->connection->enableQueryLog(true);
        $this->connection->clearQueryLog();
        $res10 = $kernel->handle(Request::create('GET', '/blog'));
        $this->assertSame(200, $res10->status());
        $q10 = count($this->connection->queryLog());

        for ($i = 11; $i <= 30; $i++) {
            $entries->create('post', [
                'title' => 'DB Perf ' . $i,
                'slug' => 'db-perf-' . $i,
                'author_id' => $i % 2 === 0 ? $admin->id : $contrib1->id,
                'featured_media_id' => $mediaId1,
                'content' => '{"version":1,"blocks":[{"id":"m' . $i . '","type":"paragraph","data":{"text":"Row ' . $i . '"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
                'published_at' => sprintf('2026-09-%02d 10:00:00', $i),
            ], $admin);
        }

        $this->connection->clearQueryLog();
        $res30 = $kernel->handle(Request::create('GET', '/blog'));
        $this->assertSame(200, $res30->status());
        $q30 = count($this->connection->queryLog());
        $this->connection->enableQueryLog(false);
        $pageCache->setEnabled($cacheWasEnabled);

        $this->assertGreaterThan(0, $q10, 'les deux mesures doivent lire la base pour être comparables');
        $this->assertSame($q10, $q30, sprintf('Expected constant query count on real DB, got %d vs %d.', $q10, $q30));
        $this->assertNotNull($notices);
        $this->assertNotNull($settings);
    }
}
