<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Media\MediaUsageRepository;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\PermalinkSettings;
use Prime\Site\RedirectRepository;
use Prime\Site\Seo\SeoSettings;
use Prime\Site\Zone\ZoneRepository;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Full end-to-end HTTP integration tests for Phase 8 (public site rendering via Aurora,
 * menus, zones, dynamic blocks, SEO/sitemap/robots, 301 redirects, previews, and admin workflows).
 */
final class SiteRenderingTest extends TestCase
{
    public function testPublicSiteFrontBlogSinglePageTemplatesTaxonomiesAuthorSearchAnd404(): void
    {
        $app = $this->createSiteApp(authenticateAs: null);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var TermRepository $terms */
        $terms = $app->get(TermRepository::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Settings $settings */
        $settings = $app->get(Settings::class);

        $settings->set('site_name', 'Prime Editorial', autoload: true);
        $settings->set('site_tagline', 'Journal moderne en PHP', autoload: true);

        $admin = $users->find(1);
        $this->assertInstanceOf(User::class, $admin);
        $users->updateFields($admin->id, [
            'username' => 'claire-moreau',
            'bio' => 'Rédactrice en chef de Prime Editorial.',
        ]);

        // Create category & tag
        $cat = $terms->create('category', 'Architecture', slug: 'architecture');
        $tag = $terms->create('tag', 'PHP 8', slug: 'php-8');

        // Create 3 published posts + 1 draft + 1 private + 1 scheduled future
        $post1 = $entries->create('post', [
            'title' => 'Bienvenue sur Aurora',
            'slug' => 'bienvenue-sur-aurora',
            'excerpt' => 'Découvrez le thème éditorial officiel.',
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'b1', 'type' => 'paragraph', 'data' => ['text' => 'Premier paragraphe éditorial <strong>riche</strong>.']],
                ],
            ], JSON_THROW_ON_ERROR),
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 09:00:00',
            'terms' => ['category' => [$cat->id], 'tag' => [$tag->id]],
            'seo' => ['title' => 'Bienvenue Aurora SEO', 'description' => 'Meta description dédiée.'],
        ], $admin);

        $entries->create('post', [
            'title' => 'Deuxième article',
            'slug' => 'deuxieme-article',
            'content' => '{"version":1,"blocks":[{"id":"b2","type":"paragraph","data":{"text":"Corps deux."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-11 09:00:00',
        ], $admin);

        $entries->create('post', [
            'title' => 'Troisième article',
            'slug' => 'troisieme-article',
            'content' => '{"version":1,"blocks":[{"id":"b3","type":"paragraph","data":{"text":"Corps trois."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-12 09:00:00',
        ], $admin);

        $draft = $entries->create('post', [
            'title' => 'Brouillon secret',
            'slug' => 'brouillon-secret',
            'content' => '{"version":1,"blocks":[{"id":"b4","type":"paragraph","data":{"text":"Confidentiel."}}]}',
            'status' => Entry::STATUS_DRAFT,
        ], $admin);

        $private = $entries->create('post', [
            'title' => 'Article privé',
            'slug' => 'article-prive',
            'content' => '{"version":1,"blocks":[{"id":"b5","type":"paragraph","data":{"text":"Privé."}}]}',
            'status' => Entry::STATUS_PRIVATE,
        ], $admin);

        // Create hierarchical pages + custom page templates
        $parentPage = $entries->create('page', [
            'title' => 'À propos',
            'slug' => 'a-propos',
            'template' => 'full-width',
            'content' => '{"version":1,"blocks":[{"id":"p1","type":"paragraph","data":{"text":"Notre histoire."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
        ], $admin);

        $entries->create('page', [
            'title' => 'Notre équipe',
            'slug' => 'equipe',
            'parent_id' => $parentPage->id,
            'template' => 'landing',
            'content' => '{"version":1,"blocks":[{"id":"p2","type":"paragraph","data":{"text":"Les membres."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
        ], $admin);

        // 1. Front page `/` renders Aurora with published posts and NEVER leaks draft/private entries
        $home = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('Prime Editorial', $home->body());
        $this->assertStringContainsString('Bienvenue sur Aurora', $home->body());
        $this->assertStringContainsString('Troisième article', $home->body());
        $this->assertStringNotContainsString('Brouillon secret', $home->body());
        $this->assertStringNotContainsString('Article privé', $home->body());
        $this->assertStringContainsString("script-src 'self'", (string) $home->header('Content-Security-Policy'));
        $this->assertStringContainsString('application/ld+json', $home->body());

        // 2. Single post `/blog/bienvenue-sur-aurora`
        $single = $kernel->handle(Request::create('GET', '/blog/bienvenue-sur-aurora'));
        $this->assertSame(200, $single->status());
        $this->assertStringContainsString('<title>Bienvenue Aurora SEO</title>', $single->body());
        $this->assertStringContainsString('Meta description dédiée.', $single->body());
        $this->assertStringContainsString('<strong>riche</strong>', $single->body());
        $this->assertStringContainsString('Architecture', $single->body());

        // 3. Hierarchical pages with custom templates (`full-width`, `landing`)
        $about = $kernel->handle(Request::create('GET', '/a-propos'));
        $this->assertSame(200, $about->status());
        $this->assertStringContainsString('Notre histoire.', $about->body());

        $team = $kernel->handle(Request::create('GET', '/a-propos/equipe'));
        $this->assertSame(200, $team->status());
        $this->assertStringContainsString('Les membres.', $team->body());

        // 4. Category & tag archives
        $catRes = $kernel->handle(Request::create('GET', '/categorie/architecture'));
        $this->assertSame(200, $catRes->status());
        $this->assertStringContainsString('Architecture', $catRes->body());
        $this->assertStringContainsString('Bienvenue sur Aurora', $catRes->body());

        $tagRes = $kernel->handle(Request::create('GET', '/etiquette/php-8'));
        $this->assertSame(200, $tagRes->status());
        $this->assertStringContainsString('PHP 8', $tagRes->body());

        // 5. Pagination (`posts_per_page = 2` -> page 2 succeeds, page 0 & page 99 -> 404)
        /** @var PermalinkSettings $permalinks */
        $permalinks = $app->get(PermalinkSettings::class);
        $permalinks->saveReading(['front_mode' => 'posts', 'posts_per_page' => 2]);

        $page2 = $kernel->handle(Request::create('GET', '/page/2'));
        $this->assertSame(200, $page2->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/page/0'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/page/99'))->status());

        // 6. Author archive (`/auteur/claire-moreau`) + pagination
        $authorRes = $kernel->handle(Request::create('GET', '/auteur/claire-moreau'));
        $this->assertSame(200, $authorRes->status());
        $this->assertStringContainsString('Rédactrice en chef de Prime Editorial.', $authorRes->body());
        $this->assertStringContainsString('Troisième article', $authorRes->body());
        $authorPage2 = $kernel->handle(Request::create('GET', '/auteur/claire-moreau/page/2'));
        $this->assertSame(200, $authorPage2->status());
        $this->assertStringContainsString('Bienvenue sur Aurora', $authorPage2->body());

        // 7. Search (`/recherche?q=...`) emits noindex & safely escapes XSS query
        $searchRes = $kernel->handle(Request::create('GET', '/recherche', ['q' => 'Aurora']));
        $this->assertSame(200, $searchRes->status());
        $this->assertStringContainsString('Bienvenue sur Aurora', $searchRes->body());
        $this->assertStringContainsString('noindex', $searchRes->body());

        $xssSearch = $kernel->handle(Request::create('GET', '/recherche', ['q' => '<script>alert(1)</script>']));
        $this->assertSame(200, $xssSearch->status());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $xssSearch->body());

        // 8. Unpublished draft & private entries return 404 to anonymous visitors
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/' . $draft->slug))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/' . $private->slug))->status());

        // 9. Unknown URL returns 404 with Aurora 404 template and noindex
        $notFound = $kernel->handle(Request::create('GET', '/page-qui-nexiste-pas'));
        $this->assertSame(404, $notFound->status());
        $this->assertStringContainsString('noindex', $notFound->body());

        // 10. Automatic 301 redirect when published post slug changes + chain shortening A -> B -> C
        $entries->update($post1->id, ['slug' => 'aurora-v2'], $admin);
        $redir1 = $kernel->handle(Request::create('GET', '/blog/bienvenue-sur-aurora'));
        $this->assertSame(301, $redir1->status());
        $this->assertSame('/blog/aurora-v2', $redir1->header('Location'));

        $entries->update($post1->id, ['slug' => 'aurora-v3'], $admin);
        $redirShortened = $kernel->handle(Request::create('GET', '/blog/bienvenue-sur-aurora'));
        $this->assertSame(301, $redirShortened->status());
        $this->assertSame('/blog/aurora-v3', $redirShortened->header('Location'));

        // 11. Static front page mode
        $permalinks->saveReading([
            'front_mode' => 'page',
            'front_page_id' => $parentPage->id,
            'posts_per_page' => 10,
        ]);
        $staticFront = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $staticFront->status());
        $this->assertStringContainsString('Notre histoire.', $staticFront->body());

        $blogArchive = $kernel->handle(Request::create('GET', '/blog'));
        $this->assertSame(200, $blogArchive->status());
        $this->assertStringContainsString('Troisième article', $blogArchive->body());
    }

    public function testMenusZonesDynamicBlocksSitemapsAndRobots(): void
    {
        $app = $this->createSiteApp(authenticateAs: null);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var MenuRepository $menus */
        $menus = $app->get(MenuRepository::class);
        /** @var ZoneRepository $zones */
        $zones = $app->get(ZoneRepository::class);
        /** @var SeoSettings $seoSettings */
        $seoSettings = $app->get(SeoSettings::class);

        $admin = $users->find(1);
        $this->assertInstanceOf(User::class, $admin);

        $pubPage = $entries->create('page', [
            'title' => 'Contact',
            'slug' => 'contact',
            'content' => '{"version":1,"blocks":[{"id":"c1","type":"paragraph","data":{"text":"Écrivez-nous."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
        ], $admin);

        $draftPage = $entries->create('page', [
            'title' => 'Brouillon Menu',
            'slug' => 'brouillon-menu',
            'content' => '{"version":1,"blocks":[]}',
            'status' => Entry::STATUS_DRAFT,
        ], $admin);

        $entries->create('post', [
            'title' => 'Article Sitemap',
            'slug' => 'article-sitemap',
            'content' => '{"version":1,"blocks":[{"id":"s1","type":"paragraph","data":{"text":"Indexable."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-14 08:00:00',
        ], $admin);

        $entries->create('post', [
            'title' => 'Article Noindex',
            'slug' => 'article-noindex',
            'content' => '{"version":1,"blocks":[{"id":"s2","type":"paragraph","data":{"text":"Caché."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-14 09:00:00',
            'seo' => ['noindex' => '1'],
        ], $admin);

        // Build primary menu with published page, draft page (must be hidden), and custom link
        $menuId = $menus->create('Navigation principale', 'primary');
        $menus->replaceItems($menuId, [
            ['label' => 'Accueil', 'type' => 'custom', 'url' => '/'],
            [
                'label' => 'Contact',
                'type' => 'entry',
                'target_id' => $pubPage->id,
                'children' => [
                    ['label' => 'Sous-lien', 'type' => 'custom', 'url' => '/sous-lien'],
                ],
            ],
            ['label' => 'Brouillon Caché', 'type' => 'entry', 'target_id' => $draftPage->id],
        ]);

        // Reject `javascript:` URLs in menus
        $this->expectException(\InvalidArgumentException::class);
        try {
            $menus->replaceItems($menuId, [
                ['label' => 'XSS', 'type' => 'custom', 'url' => 'javascript:alert(1)'],
            ]);
        } catch (\InvalidArgumentException $e) {
            // Re-verify valid menu + zones still render cleanly
            $zones->save('sidebar', [
                'version' => 1,
                'blocks' => [
                    ['id' => 'z1', 'type' => 'dynamic', 'data' => ['name' => 'recent_posts', 'attributes' => ['limit' => 3]]],
                    ['id' => 'z2', 'type' => 'dynamic', 'data' => ['name' => 'search_form', 'attributes' => []]],
                ],
            ]);

            $contactRes = $kernel->handle(Request::create('GET', '/contact'));
            $this->assertSame(200, $contactRes->status());
            $this->assertStringContainsString('aria-current="page"', $contactRes->body());
            $this->assertStringContainsString('Sous-lien', $contactRes->body());
            $this->assertStringNotContainsString('Brouillon Caché', $contactRes->body());

            // Front page renders sidebar zone with recent_posts dynamic block
            $homeRes = $kernel->handle(Request::create('GET', '/'));
            $this->assertSame(200, $homeRes->status());
            $this->assertStringContainsString('Article Sitemap', $homeRes->body());

            // Sitemap XML index & sub-sitemaps (published indexable included; draft & noindex excluded)
            $indexRes = $kernel->handle(Request::create('GET', '/sitemap.xml'));
            $this->assertSame(200, $indexRes->status());
            $this->assertStringContainsString('application/xml', (string) $indexRes->header('Content-Type'));
            $this->assertStringContainsString('<sitemapindex', $indexRes->body());
            $this->assertStringContainsString('/sitemap-post-1.xml', $indexRes->body());

            $postMap = $kernel->handle(Request::create('GET', '/sitemap-post-1.xml'));
            $this->assertSame(200, $postMap->status());
            $this->assertStringContainsString('/blog/article-sitemap', $postMap->body());
            $this->assertStringNotContainsString('/blog/article-noindex', $postMap->body());

            // robots.txt default vs global_noindex
            $robots = $kernel->handle(Request::create('GET', '/robots.txt'));
            $this->assertSame(200, $robots->status());
            $this->assertStringContainsString('Disallow: /admin', $robots->body());
            $this->assertStringContainsString('Sitemap:', $robots->body());

            $seoSettings->save(['global_noindex' => '1']);
            $robotsNoindex = $kernel->handle(Request::create('GET', '/robots.txt'));
            $this->assertSame(200, $robotsNoindex->status());
            $this->assertStringContainsString("Disallow: /\n", $robotsNoindex->body());

            throw $e;
        }
    }

    public function testAuthenticatedDraftPreviewCustomizerPreviewAndAdminWorkflows(): void
    {
        // 1. Authenticated Administrator
        $app = $this->createSiteApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Session $session */
        $session = $app->get(Session::class);

        $admin = $users->find(1);
        $this->assertInstanceOf(User::class, $admin);

        $draft = $entries->create('post', [
            'title' => 'Prévisualisation Aurora',
            'slug' => 'previsualisation-aurora',
            'content' => '{"version":1,"blocks":[{"id":"pv1","type":"paragraph","data":{"text":"Contenu en brouillon."}}]}',
            'status' => Entry::STATUS_DRAFT,
        ], $admin);

        // Authenticated preview via /admin/content/post/{id}/preview renders in active theme with noindex
        $adminPreview = $kernel->handle(Request::create('GET', '/admin/content/post/' . $draft->id . '/preview'));
        $this->assertSame(200, $adminPreview->status());
        $this->assertStringContainsString('Prévisualisation Aurora', $adminPreview->body());
        $this->assertStringContainsString('Contenu en brouillon.', $adminPreview->body());
        $this->assertStringContainsString('noindex', (string) $adminPreview->header('X-Robots-Tag'));

        // Public ?preview=1&entry_id={id} also succeeds for authenticated editor
        $publicPreview = $kernel->handle(Request::create('GET', '/', ['preview' => '1', 'entry_id' => (string) $draft->id]));
        $this->assertSame(200, $publicPreview->status());
        $this->assertStringContainsString('Prévisualisation Aurora', $publicPreview->body());

        // Customizer preview injects customizer-preview.js ONLY for themes.manage user
        $custPreview = $kernel->handle(Request::create('GET', '/', ['customizer_preview' => '1']));
        $this->assertSame(200, $custPreview->status());
        $this->assertStringContainsString('/assets/admin/js/customizer-preview.js', $custPreview->body());

        // Admin POST workflows with valid CSRF token
        $csrf = (new Csrf($session))->token();

        // Save customizer options (JSON)
        $saveCust = $kernel->handle(Request::create(
            'POST',
            '/admin/appearance/customizer/save',
            body: [
                '_csrf_token' => $csrf,
                'identity' => ['name' => 'Studio Aurora', 'tagline' => 'Design éditorial'],
                'options' => ['accent_color' => '#C9441F', 'container_width' => 1240],
            ],
            headers: ['Accept' => 'application/json'],
        ));
        $this->assertSame(200, $saveCust->status());
        $this->assertStringContainsString('"ok":true', $saveCust->body());

        // Create & save a menu via admin HTTP
        $createMenu = $kernel->handle(Request::create('POST', '/admin/appearance/menus/create', body: [
            '_csrf_token' => $csrf,
            'name' => 'Menu Pied de page',
            'location' => 'footer',
        ]));
        $this->assertSame(302, $createMenu->status());

        // Save a zone via admin HTTP
        $saveZone = $kernel->handle(Request::create('POST', '/admin/appearance/zones/footer_1/save', body: [
            '_csrf_token' => $csrf,
            'content_json' => '{"version":1,"blocks":[{"id":"f1","type":"paragraph","data":{"text":"À propos du site."}}]}',
        ]));
        $this->assertSame(302, $saveZone->status());

        // Save profile username & bio via admin HTTP
        $saveProfile = $kernel->handle(Request::create('POST', '/admin/profile/info', body: [
            '_csrf_token' => $csrf,
            'display_name' => 'Admin Prime',
            'email' => 'admin@prime.test',
            'locale' => 'fr',
            'username' => 'admin-prime',
            'bio' => 'Bio publique vérifiée.',
        ]));
        $this->assertSame(302, $saveProfile->status());
        $reloaded = $users->find($admin->id);
        $this->assertSame('admin-prime', $reloaded?->username);
        $this->assertSame('Bio publique vérifiée.', $reloaded?->bio);

        // 2. Non-admin contributor without `themes.manage` gets 403 on Appearance routes
        $contribApp = $this->createSiteApp(authenticateAs: 'contributor');
        /** @var Kernel $contribKernel */
        $contribKernel = $contribApp->get(Kernel::class);
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/appearance/themes'))->status());
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/appearance/customizer'))->status());
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/appearance/menus'))->status());
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/appearance/zones'))->status());

        // Anonymous visitor requesting customizer_preview=1 must NOT receive customizer-preview.js
        $anonApp = $this->createSiteApp(authenticateAs: null);
        /** @var Kernel $anonKernel */
        $anonKernel = $anonApp->get(Kernel::class);
        $anonCust = $anonKernel->handle(Request::create('GET', '/', ['customizer_preview' => '1']));
        $this->assertSame(200, $anonCust->status());
        $this->assertStringNotContainsString('/assets/admin/js/customizer-preview.js', $anonCust->body());
    }

    public function testRedirectRepositoryPreventsLoopsAndShortensChains(): void
    {
        $app = $this->createSiteApp(authenticateAs: null);
        /** @var RedirectRepository $redirects */
        $redirects = $app->get(RedirectRepository::class);

        $this->assertTrue($redirects->registerRedirect('/ancien', '/intermediaire', 301));
        $this->assertTrue($redirects->registerRedirect('/intermediaire', '/final', 301));

        // Chain A -> B -> C must be shortened to A -> C
        $ruleA = $redirects->findBySource('/ancien');
        $this->assertNotNull($ruleA);
        $this->assertSame('/final', $ruleA['target_path']);

        // Self-redirect is rejected safely
        $this->assertFalse($redirects->registerRedirect('/final', '/final', 301));

        // Reverse rename (/final -> /ancien) breaks any cycle by removing the stale /ancien rule
        $this->assertTrue($redirects->registerRedirect('/final', '/ancien', 301));
        $this->assertNull($redirects->findBySource('/ancien'));
        $ruleFinal = $redirects->findBySource('/final');
        $this->assertNotNull($ruleFinal);
        $this->assertSame('/ancien', $ruleFinal['target_path']);
    }

    public function testMediaZoneUsageTracksZoneImages(): void
    {
        $app = $this->createSiteApp(authenticateAs: null);
        /** @var Connection $db */
        $db = $app->get(Connection::class);
        /** @var ZoneRepository $zones */
        $zones = $app->get(ZoneRepository::class);
        /** @var MediaUsageRepository $usage */
        $usage = $app->get(MediaUsageRepository::class);

        $now = '2026-11-15 10:00:00';
        $db->table('media')->insert([
            'id' => 42,
            'disk_path' => '2026/11/banner.webp',
            'filename' => 'banner.webp',
            'original_name' => 'banner.webp',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'kind' => 'image',
            'size_bytes' => 1024,
            'title' => 'Banner',
            'alt' => 'Banner',
            'checksum' => str_repeat('a', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $zones->save('sidebar', [
            'version' => 1,
            'blocks' => [
                ['id' => 'img1', 'type' => 'image', 'data' => ['mediaId' => 42, 'url' => '/uploads/2026/11/banner.webp', 'alt' => 'Banner']],
            ],
        ]);

        $counts = $usage->countByMediaIds([42]);
        $this->assertSame(1, $counts[42] ?? 0);
        $usages = $usage->findByMedia(42);
        $this->assertCount(1, $usages);
        $this->assertSame('zone_block', $usages[0]['context']);

        // Clearing the zone removes media usage cleanly
        $zones->save('sidebar', ['version' => 1, 'blocks' => []]);
        $this->assertSame(0, $usage->countByMediaIds([42])[42] ?? 0);
    }

    private function createSiteApp(?string $authenticateAs): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'local');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.url', 'https://prime.test');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, username VARCHAR(80) NULL UNIQUE, bio TEXT NULL, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) NOT NULL UNIQUE, value LONGTEXT NULL, autoload INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE (type, parent_id, slug))');
        $pdo->exec('CREATE TABLE pc_entry_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, meta_key VARCHAR(191), meta_value LONGTEXT, UNIQUE (entry_id, meta_key))');
        $pdo->exec('CREATE TABLE pc_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy VARCHAR(40), name VARCHAR(191), slug VARCHAR(191), description TEXT NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, count INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (taxonomy, slug))');
        $pdo->exec('CREATE TABLE pc_entry_terms (entry_id INTEGER, term_id INTEGER, position INTEGER DEFAULT 0, PRIMARY KEY (entry_id, term_id))');
        $pdo->exec('CREATE TABLE pc_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, author_id INTEGER NULL, is_autosave INTEGER DEFAULT 0, title VARCHAR(255), excerpt TEXT NULL, content LONGTEXT, meta LONGTEXT NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entry_locks (entry_id INTEGER PRIMARY KEY, user_id INTEGER, acquired_at DATETIME, heartbeat_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, name VARCHAR(120), slug VARCHAR(140), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media (id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER NULL, author_id INTEGER NULL, disk_path VARCHAR(255) UNIQUE, filename VARCHAR(255), original_name VARCHAR(255), extension VARCHAR(16), mime_type VARCHAR(120), kind VARCHAR(24), size_bytes INTEGER DEFAULT 0, width INTEGER NULL, height INTEGER NULL, checksum CHAR(64), alt VARCHAR(255), title VARCHAR(255), caption TEXT NULL, description TEXT NULL, focal_x REAL DEFAULT 0.5, focal_y REAL DEFAULT 0.5, variants LONGTEXT NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_usage (media_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, context VARCHAR(100) NOT NULL, PRIMARY KEY (media_id, entry_id, context))');
        $pdo->exec('CREATE TABLE pc_menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE pc_menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT \'url\', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT \'\', target VARCHAR(16) NOT NULL DEFAULT \'_self\', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE pc_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, theme VARCHAR(80) NOT NULL, slug VARCHAR(80) NOT NULL, content LONGTEXT NOT NULL, content_html LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(theme, slug))');
        $pdo->exec('CREATE TABLE pc_redirects (id INTEGER PRIMARY KEY AUTOINCREMENT, source_path VARCHAR(255) NOT NULL UNIQUE, target_path VARCHAR(255) NOT NULL, status_code INTEGER NOT NULL DEFAULT 301, hits INTEGER NOT NULL DEFAULT 0, last_hit_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (media_id INTEGER NOT NULL, zone_id INTEGER NOT NULL, context VARCHAR(100) NOT NULL DEFAULT \'zone_block\', PRIMARY KEY (media_id, zone_id, context))');

        $now = '2026-11-15 10:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 1, '$now', '$now'), (2, 'contributor', 'Contributeur', 1, '$now', '$now')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*'), (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create')");
        $pdo->exec("INSERT INTO pc_users (id, email, username, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (1, 'admin@prime.test', 'admin', 'hash', 'Admin Prime', 'active', 'fr', 1, '$now', '$now'), (2, 'contrib@prime.test', 'contrib', 'hash', 'Contributeur', 'active', 'fr', 1, '$now', '$now')");
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        if ($authenticateAs === 'admin' || $authenticateAs === 'contributor') {
            /** @var UserRepository $userRepo */
            $userRepo = $app->get(UserRepository::class);
            /** @var Auth $auth */
            $auth = $app->get(Auth::class);
            $user = $userRepo->find($authenticateAs === 'admin' ? 1 : 2);
            if ($user !== null) {
                $auth->login($user);
            }
        }

        return $app;
    }
}
