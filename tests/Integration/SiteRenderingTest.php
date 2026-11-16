<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
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
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\Themes\Exception\ThemeInstallationException;
use Prime\Themes\ThemeAssetPublisher;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeInstaller;
use Prime\Themes\ThemeManager;
use Prime\Themes\ThemeManifestValidator;
use Prime\Themes\ThemeOptions;
use ZipArchive;

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

    public function testAuroraBootAssetsOptionsCssPublishAndDefaultThemeFailureAlert(): void
    {
        $app = $this->createSiteApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Session $session */
        $session = $app->get(Session::class);
        /** @var ThemeManager $themes */
        $themes = $app->get(ThemeManager::class);
        /** @var ThemeOptions $options */
        $options = $app->get(ThemeOptions::class);

        // Publish default Aurora options CSS so the homepage links options-{hash}.css
        $aurora = $themes->active();
        $this->assertNotNull($aurora->manifest);
        $savedInit = $options->save($aurora->manifest, ['accent_color' => '#C9441F']);
        $initialCssUrl = $savedInit['css_url'];
        $this->assertNotNull($initialCssUrl);
        $this->assertMatchesRegularExpression('#^/assets/themes/aurora/options-[a-f0-9]+\.css$#', $initialCssUrl);

        // Reset ThemeManager boot state so ThemeManager enqueues the freshly written options CSS
        $themes->resetCache();
        $homeBefore = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $homeBefore->status());
        $bodyBefore = $homeBefore->body();
        // C1: Aurora boots cleanly and emits <script src="...aurora.js..." defer> and options-{hash}.css
        $this->assertMatchesRegularExpression('#<script\s+src="/assets/themes/aurora/js/aurora\.js(?:\?v=[^"]*)?"\s+defer></script>#', $bodyBefore);
        $this->assertStringContainsString($initialCssUrl, $bodyBefore);

        // Save a new accent color via Customizer POST -> updates options-{newHash}.css on public site
        $csrf = (new Csrf($session))->token();
        $pubRes = $kernel->handle(Request::create(
            'POST',
            '/admin/appearance/customizer/save',
            body: [
                '_csrf_token' => $csrf,
                'options' => ['accent_color' => '#1D4ED8'],
            ],
            headers: ['Accept' => 'application/json'],
        ));
        $this->assertSame(200, $pubRes->status());

        $newCssUrl = $options->publishedCssUrl($aurora->manifest);
        $this->assertNotNull($newCssUrl);
        $this->assertNotSame($initialCssUrl, $newCssUrl);
        $newCssPath = $app->publicPath(ltrim($newCssUrl, '/'));
        $this->assertFileExists($newCssPath);
        $this->assertStringContainsString('#1D4ED8', (string) file_get_contents($newCssPath));

        // Fresh public request (anonymous) receives the updated options-{newHash}.css (and NOT unpublished draft values)
        $homeAfter = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $homeAfter->status());
        $this->assertStringContainsString($newCssUrl, $homeAfter->body());
        $this->assertStringNotContainsString('#00FF00', $homeAfter->body());

        // C1 + C5: When the default theme itself crashes on boot, an admin alert is created (not silent),
        // and once Aurora boots cleanly again, theme.fallback_error + notice are automatically cleared.
        $tmpRoot = sys_get_temp_dir() . '/prime_c1_default_fail_' . bin2hex(random_bytes(4));
        $auroraTmp = $tmpRoot . '/themes/aurora';
        mkdir($auroraTmp . '/src', 0755, true);
        mkdir($tmpRoot . '/public', 0755, true);
        copy(dirname(__DIR__, 2) . '/content/themes/aurora/theme.json', $auroraTmp . '/theme.json');

        $failingNs = 'AuroraBrokenTest' . bin2hex(random_bytes(3));
        $manifestJson = (string) file_get_contents($auroraTmp . '/theme.json');
        $manifestArr = json_decode($manifestJson, true);
        $this->assertIsArray($manifestArr);
        $manifestArr['namespace'] = $failingNs . '\\';
        $manifestArr['main'] = $failingNs . '\\Theme';
        file_put_contents($auroraTmp . '/theme.json', (string) json_encode($manifestArr, JSON_PRETTY_PRINT));
        file_put_contents(
            $auroraTmp . '/src/Theme.php',
            "<?php\nnamespace {$failingNs};\nuse Prime\\Themes\\ThemeContext;\nuse Prime\\Themes\\ThemeInterface;\nfinal class Theme implements ThemeInterface { public static bool \$shouldFail = true; public function boot(ThemeContext \$c): void { if (self::\$shouldFail) { throw new \\RuntimeException('Crash Aurora test'); } } }\n",
        );

        try {
            /** @var Settings $settings */
            $settings = $app->get(Settings::class);
            /** @var Notices $notices */
            $notices = $app->get(Notices::class);
            /** @var Hooks $hooks */
            $hooks = $app->get(Hooks::class);

            $pub = new ThemeAssetPublisher($tmpRoot . '/public', '/assets/themes');
            $reg = new ThemeAssetRegistry($tmpRoot . '/public');
            $opts = new ThemeOptions($settings, $pub, $tmpRoot . '/public');
            $mgr = new ThemeManager($app, $tmpRoot . '/themes', new ThemeManifestValidator(), $pub, $reg, $opts, $hooks, $settings, null, $notices, null);
            $mgr->bootActive();

            $this->assertNotNull($mgr->fallbackError());
            $this->assertSame('aurora', $mgr->fallbackError()['slug']);
            $this->assertStringContainsString('Crash Aurora test', $mgr->fallbackError()['message']);
            $themeNotices = array_values(array_filter($notices->all(), static fn ($n): bool => $n->id === ThemeManager::FALLBACK_NOTICE_ID));
            $this->assertCount(1, $themeNotices);
            $this->assertTrue($themeNotices[0]->dismissible);

            // Fix the default theme and reboot cleanly -> error & notice auto-cleared!
            $themeClass = $failingNs . '\\Theme';
            $themeClass::$shouldFail = false;

            $mgr2 = new ThemeManager($app, $tmpRoot . '/themes', new ThemeManifestValidator(), $pub, $reg, $opts, $hooks, $settings, null, $notices, null);
            $mgr2->bootActive();
            $this->assertNull($mgr2->fallbackError());
            $remainingNotices = array_values(array_filter($notices->all(), static fn ($n): bool => $n->id === ThemeManager::FALLBACK_NOTICE_ID));
            $this->assertCount(0, $remainingNotices);
        } finally {
            ThemeAssetPublisher::removeDirectory($tmpRoot);
        }
    }

    public function testDynamicBlocksRenderPerAttrsInZonesAndInsidePosts(): void
    {
        $app = $this->createSiteApp(authenticateAs: null);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Connection $db */
        $db = $app->get(Connection::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var TermRepository $terms */
        $terms = $app->get(TermRepository::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var MenuRepository $menus */
        $menus = $app->get(MenuRepository::class);
        /** @var ZoneRepository $zones */
        $zones = $app->get(ZoneRepository::class);
        /** @var DynamicBlockRegistry $dyn */
        $dyn = $app->get(DynamicBlockRegistry::class);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $admin = $users->find(1);
        $this->assertInstanceOf(User::class, $admin);

        // Insert a media row for featured image rendering in latest_entries
        $now = '2026-11-15 10:00:00';
        $db->table('media')->insert([
            'id' => 77,
            'disk_path' => '2026/11/thumb-dyn.webp',
            'filename' => 'thumb-dyn.webp',
            'original_name' => 'thumb-dyn.webp',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'kind' => 'image',
            'size_bytes' => 2048,
            'width' => 800,
            'height' => 600,
            'title' => 'Image Dyn',
            'alt' => 'Vignette dynamique',
            'checksum' => str_repeat('b', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cat = $terms->create('category', 'Guides', 'guides');
        $post1 = $entries->create('post', [
            'title' => 'Article Un',
            'slug' => 'article-un',
            'excerpt' => 'Extrait spécial article un.',
            'content' => '{"version":1,"blocks":[{"id":"a1","type":"paragraph","data":{"text":"Corps 1"}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 10:00:00',
            'terms' => [$cat->id],
            'featured_media_id' => 77,
        ], $admin);
        $entries->create('post', [
            'title' => 'Article Deux',
            'slug' => 'article-deux',
            'excerpt' => 'Extrait article deux.',
            'content' => '{"version":1,"blocks":[{"id":"a2","type":"paragraph","data":{"text":"Corps 2"}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-11 10:00:00',
            'terms' => [$cat->id],
        ], $admin);

        $menuId = $menus->create('Menu Sidebar', null);
        $menus->replaceItems($menuId, [
            ['label' => 'Lien Sidebar', 'type' => 'custom', 'url' => '/blog'],
        ]);

        // 1. latest_entries: respects limit, show_image, show_excerpt
        $htmlNoExtras = $dyn->render('latest_entries', ['title' => 'Récents', 'type' => 'post', 'limit' => 1, 'show_image' => false, 'show_excerpt' => false]);
        $this->assertStringContainsString('Article Deux', $htmlNoExtras);
        $this->assertStringNotContainsString('Article Un', $htmlNoExtras);
        $this->assertStringNotContainsString('prime-dyn__thumb', $htmlNoExtras);
        $this->assertStringNotContainsString('prime-dyn__excerpt', $htmlNoExtras);

        $htmlWithExtras = $dyn->render('latest_entries', ['title' => 'Avec médias', 'type' => 'post', 'limit' => 5, 'show_image' => true, 'show_excerpt' => true]);
        $this->assertStringContainsString('Article Un', $htmlWithExtras);
        $this->assertStringContainsString('prime-dyn__thumb', $htmlWithExtras);
        $this->assertStringContainsString('2026/11/thumb-dyn.webp', $htmlWithExtras);
        $this->assertStringContainsString('Extrait spécial article un.', $htmlWithExtras);

        // 2. term_list: respects show_count true vs false
        $termsWithCount = $dyn->render('term_list', ['taxonomy' => 'category', 'show_count' => true]);
        $this->assertStringContainsString('Guides', $termsWithCount);
        $this->assertStringContainsString('prime-dyn__count', $termsWithCount);

        $termsNoCount = $dyn->render('term_list', ['taxonomy' => 'category', 'show_count' => false]);
        $this->assertStringContainsString('Guides', $termsNoCount);
        $this->assertStringNotContainsString('prime-dyn__count', $termsNoCount);

        // 3. menu: renders chosen menu_id
        $menuHtml = $dyn->render('menu', ['menu_id' => $menuId]);
        $this->assertStringContainsString('Lien Sidebar', $menuHtml);

        // 4. search_form: renders with zero attrs
        $searchHtml = $dyn->render('search_form', []);
        $this->assertStringContainsString('role="search"', $searchHtml);
        $this->assertStringContainsString('action="/recherche"', $searchHtml);

        // 5. Plugin dynamic block schema (`text`, `number`, `toggle`, `select`) via `blocks.dynamic`
        $hooks->filter('blocks.dynamic', static function (mixed $blocks): mixed {
            if (!is_array($blocks)) {
                return $blocks;
            }
            $blocks['promo_banner'] = [
                'label' => 'Bannière promo',
                'description' => 'Bloc plugin test.',
                'defaults' => ['heading' => 'Offre', 'count' => 2, 'highlight' => true, 'tone' => 'warm'],
                'fields' => [
                    ['name' => 'heading', 'type' => 'text', 'label' => 'Titre'],
                    ['name' => 'count', 'type' => 'number', 'label' => 'Nombre', 'min' => 1, 'max' => 5],
                    ['name' => 'highlight', 'type' => 'toggle', 'label' => 'Accentuer'],
                    ['name' => 'tone', 'type' => 'select', 'label' => 'Ton', 'options' => [['value' => 'warm', 'label' => 'Chaud'], ['value' => 'cool', 'label' => 'Froid']]],
                ],
                'render' => static fn (array $a): string => sprintf('<div class="promo promo--%s">%s:%d:%s</div>', (string) $a['tone'], (string) $a['heading'], (int) $a['count'], !empty($a['highlight']) ? 'on' : 'off'),
            ];

            return $blocks;
        });

        $catalog = $dyn->catalog();
        $promoCatalog = array_values(array_filter($catalog, static fn (array $b): bool => $b['name'] === 'promo_banner'));
        $this->assertCount(1, $promoCatalog);
        $this->assertCount(4, $promoCatalog[0]['fields']);
        $this->assertSame('<div class="promo promo--cool">Bienvenue:3:off</div>', $dyn->render('promo_banner', ['heading' => 'Bienvenue', 'count' => 3, 'highlight' => false, 'tone' => 'cool']));

        // 6. Zone mixing static + dynamic blocks renders both
        $zones->save('sidebar', [
            'version' => 1,
            'blocks' => [
                ['id' => 's1', 'type' => 'paragraph', 'data' => ['text' => 'Bloc statique sidebar.']],
                ['id' => 'd1', 'type' => 'dynamic', 'data' => ['name' => 'latest_entries', 'attrs' => ['limit' => 2, 'show_excerpt' => true]]],
                ['id' => 'd2', 'type' => 'dynamic', 'data' => ['name' => 'term_list', 'attrs' => ['taxonomy' => 'category', 'show_count' => true]]],
                ['id' => 'd3', 'type' => 'dynamic', 'data' => ['name' => 'menu', 'attrs' => ['menu_id' => $menuId]]],
                ['id' => 'd4', 'type' => 'dynamic', 'data' => ['name' => 'search_form', 'attrs' => []]],
            ],
        ]);
        $liveSidebar = $zones->renderLive('sidebar', $dyn);
        $this->assertStringContainsString('Bloc statique sidebar.', $liveSidebar);
        $this->assertStringContainsString('Article Deux', $liveSidebar);
        $this->assertStringContainsString('Guides', $liveSidebar);
        $this->assertStringContainsString('Lien Sidebar', $liveSidebar);
        $this->assertStringContainsString('role="search"', $liveSidebar);

        // 7. Dynamic block embedded directly INSIDE a published post renders live on public URL
        $postWithDyn = $entries->create('post', [
            'title' => 'Article avec bloc dynamique',
            'slug' => 'article-avec-dynamique',
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'p0', 'type' => 'paragraph', 'data' => ['text' => 'Intro avant bloc dynamique.']],
                    ['id' => 'pd1', 'type' => 'dynamic', 'data' => ['name' => 'term_list', 'attrs' => ['title' => 'Rubriques live', 'taxonomy' => 'category', 'show_count' => true]]],
                ],
            ]),
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-12 10:00:00',
        ], $admin);

        $singleRes = $kernel->handle(Request::create('GET', '/blog/' . $postWithDyn->slug));
        $this->assertSame(200, $singleRes->status());
        $this->assertStringContainsString('Intro avant bloc dynamique.', $singleRes->body());
        $this->assertStringContainsString('Rubriques live', $singleRes->body());
        $this->assertStringContainsString('Guides', $singleRes->body());
        $this->assertNotNull($post1->id);
    }

    public function testEditorPreviewUrlLiveUrlPatternVoirLinksAndSitemapImages(): void
    {
        $app = $this->createSiteApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Connection $db */
        $db = $app->get(Connection::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);

        $admin = $users->find(1);
        $this->assertInstanceOf(User::class, $admin);

        // Seed 3 media images (featured, image block, gallery)
        $now = '2026-11-15 10:00:00';
        foreach ([101 => 'feat.jpg', 102 => 'inline.jpg', 103 => 'gal1.jpg', 104 => 'gal2.jpg'] as $id => $file) {
            $db->table('media')->insert([
                'id' => $id,
                'disk_path' => '2026/11/' . $file,
                'filename' => $file,
                'original_name' => $file,
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'kind' => 'image',
                'size_bytes' => 1500,
                'title' => 'Titre ' . $file,
                'alt' => 'Alt ' . $file,
                'checksum' => str_repeat(dechex($id % 16), 64),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $published = $entries->create('post', [
            'title' => 'Article illustré',
            'slug' => 'article-illustre',
            'content' => json_encode([
                'version' => 1,
                'blocks' => [
                    ['id' => 'i1', 'type' => 'image', 'data' => ['mediaId' => 102, 'url' => '/uploads/2026/11/inline.jpg', 'alt' => 'Image corps']],
                    ['id' => 'g1', 'type' => 'gallery', 'data' => ['columns' => 2, 'items' => [
                        ['mediaId' => 103, 'url' => '/uploads/2026/11/gal1.jpg', 'alt' => 'Galerie 1'],
                        ['mediaId' => 104, 'url' => '/uploads/2026/11/gal2.jpg', 'alt' => 'Galerie 2'],
                    ]]],
                ],
            ]),
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 10:00:00',
            'featured_media_id' => 101,
        ], $admin);

        $draft = $entries->create('post', [
            'title' => 'Brouillon caché',
            'slug' => 'brouillon-cache',
            'content' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Brouillon"}}]}',
            'status' => Entry::STATUS_DRAFT,
        ], $admin);

        // C3: Entry::previewUrl() returns /admin/content/{type}/{id}/preview
        $this->assertSame('/admin/content/post/' . $published->id . '/preview', $published->previewUrl());

        // C3: Editor page includes live URL pattern + previewUrl + Voir public URL link for published entry
        $editRes = $kernel->handle(Request::create('GET', '/admin/content/post/' . $published->id . '/edit'));
        $this->assertSame(200, $editRes->status());
        $this->assertStringContainsString('data-prime-url-pattern="/blog/__PRIME_SLUG__"', $editRes->body());
        $this->assertStringContainsString('/blog/article-illustre', $editRes->body());
        $this->assertStringContainsString('/admin/content/post/' . $published->id . '/preview', $editRes->body());
        $this->assertStringContainsString('data-prime-view-public', $editRes->body());

        // C3: Content list includes Voir for published, and working previewUrl
        $listRes = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $listRes->status());
        $this->assertStringContainsString('href="/blog/article-illustre"', $listRes->body());
        $this->assertStringContainsString('href="/admin/content/post/' . $published->id . '/preview"', $listRes->body());
        $this->assertStringNotContainsString('href="/blog/brouillon-cache"', $listRes->body());
        $this->assertNotNull($draft->id);

        // C6: Sitemap includes xmlns:image and <image:image> for featured + image + gallery blocks
        $sitemapRes = $kernel->handle(Request::create('GET', '/sitemap-post-1.xml'));
        $this->assertSame(200, $sitemapRes->status());
        $xml = $sitemapRes->body();
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
        $this->assertStringContainsString('<image:loc>https://prime.test/uploads/2026/11/feat.jpg</image:loc>', $xml);
        $this->assertStringContainsString('<image:loc>https://prime.test/uploads/2026/11/inline.jpg</image:loc>', $xml);
        $this->assertStringContainsString('<image:loc>https://prime.test/uploads/2026/11/gal1.jpg</image:loc>', $xml);
        $this->assertStringContainsString('<image:loc>https://prime.test/uploads/2026/11/gal2.jpg</image:loc>', $xml);
        $this->assertStringNotContainsString('brouillon-cache', $xml);
    }

    public function testC7VisibilityPreviewRbacSlugRedirectsStaticFrontPermalinksReservedSlugsMenusAndZeroNPlusOne(): void
    {
        $app = $this->createSiteApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Connection $db */
        $db = $app->get(Connection::class);
        /** @var EntryService $entries */
        $entries = $app->get(EntryService::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Settings $settings */
        $settings = $app->get(Settings::class);
        /** @var Session $session */
        $session = $app->get(Session::class);
        /** @var MenuRepository $menus */
        $menus = $app->get(MenuRepository::class);

        $admin = $users->find(1);
        $contrib1 = $users->find(2);
        $this->assertInstanceOf(User::class, $admin);
        $this->assertInstanceOf(User::class, $contrib1);

        // Create a second contributor (user #3) to verify cross-contributor preview denial (403)
        $db->table('users')->insert([
            'id' => 3,
            'email' => 'contrib2@prime.test',
            'username' => 'contrib2',
            'password_hash' => 'hash',
            'display_name' => 'Contributeur Deux',
            'status' => 'active',
            'locale' => 'fr',
            'session_version' => 1,
            'created_at' => '2026-11-15 10:00:00',
            'updated_at' => '2026-11-15 10:00:00',
        ]);
        $db->table('user_roles')->insert(['user_id' => 3, 'role_id' => 2]);
        $contrib2 = $users->find(3);
        $this->assertInstanceOf(User::class, $contrib2);

        // 1. Visibility: draft, scheduled (future), trashed must all return 404 publicly
        $draftPost = $entries->create('post', [
            'title' => 'Brouillon Contrib 1',
            'slug' => 'brouillon-c1',
            'content' => '{"version":1,"blocks":[{"id":"d1","type":"paragraph","data":{"text":"Secret draft."}}]}',
            'status' => Entry::STATUS_DRAFT,
        ], $contrib1);

        $scheduledPost = $entries->create('post', [
            'title' => 'Planifié futur',
            'slug' => 'planifie-futur',
            'content' => '{"version":1,"blocks":[{"id":"s1","type":"paragraph","data":{"text":"Future."}}]}',
            'status' => Entry::STATUS_SCHEDULED,
            'published_at' => '2099-01-01 00:00:00',
        ], $admin);

        $trashedPost = $entries->create('post', [
            'title' => 'En corbeille',
            'slug' => 'en-corbeille',
            'content' => '{"version":1,"blocks":[{"id":"t1","type":"paragraph","data":{"text":"Trashed."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-01 10:00:00',
        ], $admin);
        $entries->trash($trashedPost->id, $admin);

        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/brouillon-c1'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/planifie-futur'))->status());
        $this->assertSame(404, $kernel->handle(Request::create('GET', '/blog/en-corbeille'))->status());
        $this->assertNotNull($scheduledPost->id);

        // 2. Preview RBAC: author (contrib1) CAN preview their own draft (200); contrib2 gets 403!
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $auth->login($contrib1);
        $this->assertSame(200, $kernel->handle(Request::create('GET', $draftPost->previewUrl()))->status());

        $auth->login($contrib2);
        $this->assertSame(403, $kernel->handle(Request::create('GET', $draftPost->previewUrl()))->status());
        // Settings & Themes permissions denied to contributor
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/settings/reading'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/settings/permalinks'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/settings/seo'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/appearance/themes'))->status());

        // Switch back to admin for remaining admin checks
        $auth->login($admin);

        // 3. Slug rename chain A -> B -> C creates 301 & shortens A -> C
        $postChain = $entries->create('post', [
            'title' => 'Chaîne',
            'slug' => 'slug-a',
            'content' => '{"version":1,"blocks":[{"id":"c1","type":"paragraph","data":{"text":"Chaîne."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
            'published_at' => '2026-11-10 10:00:00',
        ], $admin);
        $postChain = $entries->update($postChain->id, ['slug' => 'slug-b'], $admin);
        $postChain = $entries->update($postChain->id, ['slug' => 'slug-c'], $admin);

        $rA = $kernel->handle(Request::create('GET', '/blog/slug-a'));
        $this->assertSame(301, $rA->status());
        $this->assertSame('/blog/slug-c', $rA->header('Location'));
        $rB = $kernel->handle(Request::create('GET', '/blog/slug-b'));
        $this->assertSame(301, $rB->status());
        $this->assertSame('/blog/slug-c', $rB->header('Location'));
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/blog/slug-c'))->status());

        /** @var PermalinkSettings $permalinks */
        $permalinks = $app->get(PermalinkSettings::class);
        $this->assertNotNull($settings);

        // 4. Static front page: `/` renders page AND old `/accueil-statique` 301-redirects to `/`
        $frontPage = $entries->create('page', [
            'title' => 'Accueil Statique',
            'slug' => 'accueil-statique',
            'content' => '{"version":1,"blocks":[{"id":"fp1","type":"paragraph","data":{"text":"Hero statique."}}]}',
            'status' => Entry::STATUS_PUBLISHED,
        ], $admin);
        $permalinks->saveReading([
            'front_mode' => PermalinkSettings::FRONT_MODE_PAGE,
            'front_page_id' => $frontPage->id,
        ]);

        $frontRes = $kernel->handle(Request::create('GET', '/'));
        $this->assertSame(200, $frontRes->status());
        $this->assertStringContainsString('Hero statique.', $frontRes->body());

        $oldFrontUrl = $kernel->handle(Request::create('GET', '/accueil-statique'));
        $this->assertSame(301, $oldFrontUrl->status());
        $this->assertSame('/', $oldFrontUrl->header('Location'));

        // Restore posts front mode for permalink & N+1 checks
        $permalinks->saveReading([
            'front_mode' => PermalinkSettings::FRONT_MODE_POSTS,
            'posts_per_page' => 50,
        ]);

        // 5. Permalink structure change -> `/{annee}/{mois}/{slug}`
        $permalinks->savePermalinks(['post_structure' => PermalinkSettings::POST_YEAR_MONTH_SLUG]);
        $ymRes = $kernel->handle(Request::create('GET', '/2026/11/slug-c'));
        $this->assertSame(200, $ymRes->status());
        $this->assertStringContainsString('Chaîne.', $ymRes->body());
        $permalinks->savePermalinks(['post_structure' => PermalinkSettings::POST_BLOG_SLUG]);

        // 6. Reserved slug rejected
        try {
            $entries->create('page', [
                'title' => 'Admin Fake',
                'slug' => 'admin',
                'content' => '{"version":1,"blocks":[{"id":"r1","type":"paragraph","data":{"text":"X"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
            ], $admin);
            $this->fail('Expected reserved slug "admin" to be rejected.');
        } catch (ContentValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        // 7. Direct POST with `javascript:` menu item is REJECTED (422) & valid nested menu renders `aria-current="page"` while hiding unpublished entry
        $menuId = $menus->create('Menu Sécurité', 'primary');
        $csrf = (new Csrf($session))->token();
        $postXssMenu = $kernel->handle(Request::create(
            'POST',
            '/admin/appearance/menus/' . $menuId . '/save',
            body: [
                '_csrf_token' => $csrf,
                'name' => 'Menu Sécurité',
                'location' => 'primary',
                'items_json' => (string) json_encode([
                    ['label' => 'XSS', 'type' => 'custom', 'url' => 'javascript:alert(1)'],
                ]),
            ],
            headers: ['Accept' => 'application/json'],
        ));
        $this->assertSame(422, $postXssMenu->status());

        $postValidMenu = $kernel->handle(Request::create(
            'POST',
            '/admin/appearance/menus/' . $menuId . '/save',
            body: [
                '_csrf_token' => $csrf,
                'name' => 'Menu Sécurité',
                'location' => 'primary',
                'items_json' => (string) json_encode([
                    ['label' => 'Accueil', 'type' => 'custom', 'url' => '/', 'children' => [
                        ['label' => 'Article C', 'type' => 'entry', 'target_id' => $postChain->id],
                    ]],
                    ['label' => 'Brouillon caché', 'type' => 'entry', 'target_id' => $draftPost->id],
                ]),
            ],
        ));
        $this->assertSame(302, $postValidMenu->status());

        $publicPost = $kernel->handle(Request::create('GET', '/blog/slug-c'));
        $this->assertSame(200, $publicPost->status());
        $this->assertStringNotContainsString('javascript:alert(1)', $publicPost->body());
        $this->assertStringNotContainsString('Brouillon caché', $publicPost->body());
        $this->assertStringContainsString('aria-current="page"', $publicPost->body());
        $this->assertStringContainsString('site-nav__submenu', $publicPost->body());

        // 8. Zero N+1: constant SQL query count for rendering 10 posts vs 30 posts on `/blog`!
        $permalinks->saveReading(['front_mode' => PermalinkSettings::FRONT_MODE_POSTS, 'posts_per_page' => 50]);
        for ($i = 1; $i <= 10; $i++) {
            $entries->create('post', [
                'title' => 'Perf Post ' . $i,
                'slug' => 'perf-post-' . $i,
                'author_id' => $i % 2 === 0 ? $admin->id : $contrib1->id,
                'content' => '{"version":1,"blocks":[{"id":"p' . $i . '","type":"paragraph","data":{"text":"Texte ' . $i . '"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
                'published_at' => sprintf('2026-10-%02d 10:00:00', $i),
            ], $admin);
        }

        // Warm caches once, then count queries for 10+ posts
        $kernel->handle(Request::create('GET', '/blog'));
        $db->enableQueryLog(true);
        $db->clearQueryLog();
        $res10 = $kernel->handle(Request::create('GET', '/blog'));
        $this->assertSame(200, $res10->status());
        $queriesAt10 = count($db->queryLog());

        // Add 20 more posts (30+ total rendered on the exact same page!)
        for ($i = 11; $i <= 30; $i++) {
            $entries->create('post', [
                'title' => 'Perf Post ' . $i,
                'slug' => 'perf-post-' . $i,
                'author_id' => $i % 2 === 0 ? $admin->id : $contrib1->id,
                'content' => '{"version":1,"blocks":[{"id":"p' . $i . '","type":"paragraph","data":{"text":"Texte ' . $i . '"}}]}',
                'status' => Entry::STATUS_PUBLISHED,
                'published_at' => sprintf('2026-09-%02d 10:00:00', $i),
            ], $admin);
        }

        $db->clearQueryLog();
        $res30 = $kernel->handle(Request::create('GET', '/blog'));
        $this->assertSame(200, $res30->status());
        $queriesAt30 = count($db->queryLog());
        $db->enableQueryLog(false);

        $this->assertStringContainsString('Perf Post 30', $res30->body());
        $this->assertSame(
            $queriesAt10,
            $queriesAt30,
            sprintf('Expected constant SQL query count (zero N+1), got %d for 10 posts vs %d for 30 posts.', $queriesAt10, $queriesAt30),
        );
    }

    public function testChildThemeFallbackAlertSingleNoticeAndMaliciousZips(): void
    {
        $app = $this->createSiteApp(authenticateAs: 'admin');
        /** @var Settings $settings */
        $settings = $app->get(Settings::class);
        /** @var Notices $notices */
        $notices = $app->get(Notices::class);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $tmpRoot = sys_get_temp_dir() . '/prime_c7_themes_' . bin2hex(random_bytes(4));
        $themesDir = $tmpRoot . '/themes';
        $publicDir = $tmpRoot . '/public';
        $tmpUpload = $tmpRoot . '/tmp';
        mkdir($themesDir . '/aurora/src', 0755, true);
        mkdir($themesDir . '/aurora/templates', 0755, true);
        mkdir($themesDir . '/aurora/assets/css', 0755, true);
        mkdir($themesDir . '/aurora-child/src', 0755, true);
        mkdir($themesDir . '/aurora-child/templates', 0755, true);
        mkdir($themesDir . '/broken-one/src', 0755, true);
        mkdir($themesDir . '/broken-two/src', 0755, true);
        mkdir($publicDir, 0755, true);
        mkdir($tmpUpload, 0755, true);

        $suffix = bin2hex(random_bytes(3));
        $writeTheme = static function (string $dir, string $slug, string $name, string $ns, string $bootCode, ?string $parent = null): void {
            $manifest = [
                'name' => $name,
                'slug' => $slug,
                'version' => '1.0.0',
                'description' => $name,
                'author' => 'Test',
                'license' => 'proprietary',
                'requires_prime' => '>=0.8.0',
                'requires_php' => '>=8.3',
                'namespace' => $ns . '\\',
                'main' => $ns . '\\Theme',
            ];
            if ($parent !== null) {
                $manifest['parent'] = $parent;
            }
            file_put_contents($dir . '/theme.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
            file_put_contents(
                $dir . '/src/Theme.php',
                "<?php\nnamespace {$ns};\nuse Prime\\Themes\\ThemeContext;\nuse Prime\\Themes\\ThemeInterface;\nfinal class Theme implements ThemeInterface { public function boot(ThemeContext \$context): void { {$bootCode} } }\n",
            );
        };

        $writeTheme($themesDir . '/aurora', 'aurora', 'Aurora', 'AuroraBase' . $suffix, '');
        $writeTheme($themesDir . '/aurora-child', 'aurora-child', 'Aurora Enfant', 'AuroraChild' . $suffix, '', 'aurora');
        $writeTheme($themesDir . '/broken-one', 'broken-one', 'Broken One', 'BrokenOne' . $suffix, 'throw new \RuntimeException("Boom 1");');
        $writeTheme($themesDir . '/broken-two', 'broken-two', 'Broken Two', 'BrokenTwo' . $suffix, 'throw new \RuntimeException("Boom 2");');

        try {
            $pub = new ThemeAssetPublisher($publicDir, '/assets/themes');
            $reg = new ThemeAssetRegistry($publicDir);
            $opts = new ThemeOptions($settings, $pub, $publicDir);
            $validator = new ThemeManifestValidator();

            // 1. Failing theme #1 falls back to Aurora with 1 dismissible notice
            $settings->set(ThemeManager::SETTING_ACTIVE, 'broken-one');
            $mgr1 = new ThemeManager($app, $themesDir, $validator, $pub, $reg, $opts, $hooks, $settings, null, $notices, null);
            $mgr1->bootActive();
            $this->assertSame('aurora', $mgr1->active()->slug);
            $this->assertTrue($mgr1->didFallBackToDefault());
            $fallbackNotices1 = array_values(array_filter($notices->all(), static fn ($n): bool => $n->source === 'themes'));
            $this->assertCount(1, $fallbackNotices1);
            $this->assertSame(ThemeManager::FALLBACK_NOTICE_ID, $fallbackNotices1[0]->id);

            // 2. Failing theme #2 REPLACES the notice (no accumulation: still strictly 1 notice!)
            $settings->set(ThemeManager::SETTING_ACTIVE, 'broken-two');
            $mgr2 = new ThemeManager($app, $themesDir, $validator, $pub, $reg, $opts, $hooks, $settings, null, $notices, null);
            $mgr2->bootActive();
            $this->assertSame('aurora', $mgr2->active()->slug);
            $fallbackNotices2 = array_values(array_filter($notices->all(), static fn ($n): bool => $n->source === 'themes'));
            $this->assertCount(1, $fallbackNotices2);
            $this->assertStringContainsString('broken-two', $fallbackNotices2[0]->message);

            // 3. Activating a healthy child theme clears BOTH theme.fallback_error AND the notice!
            $activatedChild = $mgr2->activate('aurora-child');
            $this->assertSame('aurora-child', $activatedChild->slug);
            $this->assertNull($mgr2->fallbackError());
            $this->assertNull($settings->get(ThemeManager::SETTING_FALLBACK_ERROR));
            $fallbackNoticesAfter = array_values(array_filter($notices->all(), static fn ($n): bool => $n->source === 'themes'));
            $this->assertCount(0, $fallbackNoticesAfter);

            // Booting the child theme loads parent (`aurora`) cleanly
            $mgr3 = new ThemeManager($app, $themesDir, $validator, $pub, $reg, $opts, $hooks, $settings, null, $notices, null);
            $mgr3->bootActive();
            $this->assertSame('aurora-child', $mgr3->active()->slug);
            $this->assertSame('aurora', $mgr3->activeParent()?->slug);

            // 4. Malicious ZIPs (path traversal & invalid manifest) rejected by ThemeInstaller
            $installer = new ThemeInstaller($themesDir, $tmpUpload, $validator, $mgr3, $hooks);
            $evilZip = $tmpRoot . '/evil.zip';
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($evilZip, ZipArchive::CREATE) === true);
            $zip->addFromString('../escape.php', '<?php echo "pwn";');
            $zip->close();

            try {
                $installer->installFromZip($evilZip);
                $this->fail('Expected path-traversal ZIP to be rejected.');
            } catch (ThemeInstallationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }

            $noManifestZip = $tmpRoot . '/nomanifest.zip';
            $zip2 = new ZipArchive();
            $this->assertTrue($zip2->open($noManifestZip, ZipArchive::CREATE) === true);
            $zip2->addFromString('badtheme/index.php', '<?php echo 1;');
            $zip2->close();

            try {
                $installer->installFromZip($noManifestZip);
                $this->fail('Expected manifest-less ZIP to be rejected.');
            } catch (ThemeInstallationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        } finally {
            ThemeAssetPublisher::removeDirectory($tmpRoot);
        }
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
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*'), (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own')");
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
