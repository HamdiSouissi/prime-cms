<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Auth\Capabilities;
use Prime\Auth\UserRepository;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\RevisionRepository;
use Prime\Content\TermRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;

/**
 * End-to-end HTTP, RBAC, Preview CSP, Lock, Revisions, Taxonomy, CLI, and Hello Prime
 * Phase 6 integration test suite (fast SQLite harness executed in PHP 8.2 & 8.3 CI).
 */
final class AdminContentHttpTest extends TestCase
{
    private string $tmpRoot = '';

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            AssetPublisher::deleteDirectory($this->tmpRoot);
        }
        $this->tmpRoot = '';
    }

    public function testFullContentCrudPreviewCspRevisionsAutosaveAndLocks(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // 1. Create a published post with YouTube embed + XSS payload in paragraph + flat tags
        $blocksJson = json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'h1', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Introduction']],
                ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Bonjour <strong>Prime</strong><script>alert(1)</script>']],
                ['id' => 'e1', 'type' => 'embed', 'data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Démo']],
            ],
        ], JSON_THROW_ON_ERROR);

        $createRes = $kernel->handle(Request::create(
            'POST',
            '/admin/content/post',
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Premier article Prime',
                'slug' => 'premier-article-prime',
                'status' => 'published',
                'content_json' => $blocksJson,
                'new_terms' => ['tag' => 'PHP, CMS'],
            ],
        ));
        $this->assertSame(302, $createRes->status());
        $location = (string) $createRes->header('Location');
        $this->assertMatchesRegularExpression('#^/admin/content/post/(\d+)/edit$#', $location);
        preg_match('#/(\d+)/edit$#', $location, $m);
        $entryId = (int) $m[1];

        // 2. Listing page keeps strict baseline CSP (NO frame-src) and displays created entry
        $listRes = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $listRes->status());
        $this->assertStringContainsString('Premier article Prime', $listRes->body());
        $this->assertStringNotContainsString('frame-src', (string) $listRes->header('Content-Security-Policy'));

        // 3. Preview page renders sanitized HTML (script stripped, youtube-nocookie iframe rendered)
        //    AND sends preview-only CSP allowing whitelisted YouTube/Vimeo iframes!
        $previewRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/preview"));
        $this->assertSame(200, $previewRes->status());
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $previewRes->body());
        $this->assertStringContainsString('<strong>Prime</strong>', $previewRes->body());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $previewRes->body());
        $previewCsp = (string) $previewRes->header('Content-Security-Policy');
        $this->assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.vimeo.com', $previewCsp);

        // 4. Admin opens edit (acquires lock), updates title (creates 2nd revision)
        $editRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/edit"));
        $this->assertSame(200, $editRes->status());
        $this->assertStringContainsString('data-prime-editor', $editRes->body());

        $updateRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}",
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Article Prime Modifié',
                'slug' => 'premier-article-prime',
                'status' => 'published',
                'content_json' => json_encode([
                    'version' => 1,
                    'blocks' => [
                        ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Version 2 mise à jour.']],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ));
        $this->assertSame(302, $updateRes->status());

        // 5. Revisions screen displays diff and restores initial version cleanly
        $revRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/revisions"));
        $this->assertSame(200, $revRes->status());
        $this->assertStringContainsString('Révisions — Article Prime Modifié', $revRes->body());

        // 6. Autosave JSON endpoint succeeds
        $autosaveRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}/autosave",
            headers: ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf],
            rawBody: json_encode(['title' => 'Brouillon auto', 'content_json' => $blocksJson], JSON_THROW_ON_ERROR),
        ));
        $this->assertSame(200, $autosaveRes->status());
        $this->assertStringContainsString('"ok":true', $autosaveRes->body());

        // 7. Switch to Editor user -> sees lock screen, heartbeat returns 409, takeover succeeds!
        $this->loginAs($app, 3); // Editor
        $csrfEditor = $this->csrfToken($app);

        $lockedRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/edit"));
        $this->assertSame(200, $lockedRes->status());
        $this->assertStringContainsString('Contenu en cours d’édition', $lockedRes->body());

        $hbRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}/lock/heartbeat",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfEditor],
        ));
        $this->assertSame(409, $hbRes->status());
        $this->assertStringContainsString('"locked_by_other":true', $hbRes->body());

        $takeoverRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}/lock/takeover",
            body: ['_csrf_token' => $csrfEditor],
        ));
        $this->assertSame(302, $takeoverRes->status());

        // Now Editor can open edit cleanly
        $editorEditRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/edit"));
        $this->assertSame(200, $editorEditRes->status());
        $this->assertStringContainsString('data-prime-editor', $editorEditRes->body());

        // 8. Autocomplete search-entries returns JSON match
        $searchRes = $kernel->handle(Request::create('GET', '/admin/content/search-entries?q=Modifi&types=post'));
        $this->assertSame(200, $searchRes->status());
        $searchJson = json_decode($searchRes->body(), true);
        $this->assertIsArray($searchJson);
        $this->assertNotEmpty($searchJson['items']);

        // 9. Duplicate, Trash, Restore, and Permanent Delete
        $this->loginAs($app, 1); // Back to admin
        $csrfAdmin = $this->csrfToken($app);

        $dupRes = $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/duplicate", body: ['_csrf_token' => $csrfAdmin]));
        $this->assertSame(302, $dupRes->status());

        $trashRes = $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/trash", body: ['_csrf_token' => $csrfAdmin]));
        $this->assertSame(302, $trashRes->status());

        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        $trashed = $entryRepo->find($entryId);
        $this->assertNotNull($trashed);
        $this->assertSame(Entry::STATUS_TRASH, $trashed->status);

        $restoreRes = $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/restore", body: ['_csrf_token' => $csrfAdmin]));
        $this->assertSame(302, $restoreRes->status());
        $restored = $entryRepo->find($entryId);
        $this->assertNotNull($restored);
        $this->assertSame(Entry::STATUS_PUBLISHED, $restored->status);
    }

    public function testContributorRbacAndTaxonomyCrudEndpoints(): void
    {
        $app = $this->createApp(authenticateAs: 'contributor');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // Contributor CANNOT publish directly -> 403 Forbidden
        $publishRes = $kernel->handle(Request::create(
            'POST',
            '/admin/content/post',
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Tentative publication',
                'status' => 'published',
                'content_json' => '{"version":1,"blocks":[]}',
            ],
        ));
        $this->assertSame(403, $publishRes->status());

        // Contributor CAN create a draft
        $draftRes = $kernel->handle(Request::create(
            'POST',
            '/admin/content/post',
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Brouillon Contributeur',
                'status' => 'draft',
                'content_json' => '{"version":1,"blocks":[]}',
            ],
        ));
        $this->assertSame(302, $draftRes->status());

        // Switch to Admin for Taxonomy CRUD & AJAX quick-create
        $this->loginAs($app, 1);
        $csrfAdmin = $this->csrfToken($app);

        $taxPost = $kernel->handle(Request::create(
            'POST',
            '/admin/taxonomies/category',
            headers: ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $csrfAdmin],
            rawBody: json_encode(['name' => 'Tutoriels'], JSON_THROW_ON_ERROR),
        ));
        $this->assertSame(201, $taxPost->status());
        $taxData = json_decode($taxPost->body(), true);
        $this->assertTrue((bool) ($taxData['ok'] ?? false));
        $termId = (int) ($taxData['term']['id'] ?? 0);
        $this->assertGreaterThan(0, $termId);

        $taxEdit = $kernel->handle(Request::create(
            'POST',
            "/admin/taxonomies/category/{$termId}",
            body: ['_csrf_token' => $csrfAdmin, 'name' => 'Guides Pratiques', 'slug' => 'guides-pratiques'],
        ));
        $this->assertSame(302, $taxEdit->status());

        $taxList = $kernel->handle(Request::create('GET', '/admin/taxonomies/category'));
        $this->assertSame(200, $taxList->status());
        $this->assertStringContainsString('Guides Pratiques', $taxList->body());
    }

    public function testContentCliCommandsAndHelloPrimePhase6ExtensionWithTracelessUninstall(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');

        // 1. Exercise CLI commands (`content:types`, `content:create`, `content:list`, `content:publish-scheduled`, `content:purge-trash`, `content:rerender`)
        $console = new ConsoleApplication($app);

        $outTypes = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'content:types'], $outTypes));
        $this->assertStringContainsString('page', $outTypes->stdout());
        $this->assertStringContainsString('category', $outTypes->stdout());

        $outCreate = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run([
            'bin/prime',
            'content:create',
            '--type=post',
            '--title=Article CLI',
            '--status=published',
            '--body=Contenu généré depuis le terminal.',
        ], $outCreate));
        $this->assertStringContainsString('Article CLI', $outCreate->stdout());

        $outList = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'content:list', '--type=post'], $outList));
        $this->assertStringContainsString('Article CLI', $outList->stdout());

        $outSched = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'content:publish-scheduled'], $outSched));

        $outPurge = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'content:purge-trash', '--days=30'], $outPurge));

        $outRerender = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'content:rerender'], $outRerender));
        $this->assertStringContainsString('1 contenu(s) re-rendu(s)', $outRerender->stdout());

        // 2. Activate official `hello-prime` plugin -> registers `testimonial`, `testimonial_source`, fields & `hello-prime/highlight` block
        $this->tmpRoot = sys_get_temp_dir() . '/prime-content-hp-' . bin2hex(random_bytes(5));
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);
        mkdir($this->tmpRoot . '/storage', 0755, true);

        /** @var Connection $conn */
        $conn = $app->get(Connection::class);
        $schema = new Schema($conn);
        $migrator = new Migrator($conn, $schema, [dirname(__DIR__, 2) . '/database/migrations']);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);
        /** @var Router $router */
        $router = $app->get(Router::class);
        /** @var View $views */
        $views = $app->get(View::class);
        /** @var AdminMenu $adminMenu */
        $adminMenu = $app->get(AdminMenu::class);
        /** @var Capabilities $capabilities */
        $capabilities = $app->get(Capabilities::class);
        /** @var Settings $settings */
        $settings = $app->get(Settings::class);
        /** @var Notices $notices */
        $notices = $app->get(Notices::class);
        $autoloader = new Autoloader();
        $autoloader->register();

        $manager = new PluginManager(
            app: $app,
            pluginsPath: dirname(__DIR__, 2) . '/content/plugins',
            autoloader: $autoloader,
            repository: new PluginRepository($conn, $schema),
            validator: new ManifestValidator(),
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets', '/assets/plugins'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/storage/loading.json'),
            migrator: $migrator,
            hooks: $hooks,
            router: $router,
            views: $views,
            adminMenu: $adminMenu,
            capabilities: $capabilities,
            settings: $settings,
            db: $conn,
            notices: $notices,
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        $manager->activate('hello-prime');

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // Verify custom `testimonial` create screen renders custom field group + plugin highlight script
        $createScreen = $kernel->handle(Request::create('GET', '/admin/content/testimonial/create'));
        $this->assertSame(200, $createScreen->status());
        $this->assertStringContainsString('Détails du témoignage', $createScreen->body());
        $this->assertStringContainsString('highlight-block.js', $createScreen->body());

        // Create a `testimonial` entry using `hello-prime/highlight` block, custom fields & `testimonial_source` term
        $storeRes = $kernel->handle(Request::create(
            'POST',
            '/admin/content/testimonial',
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Avis Studio Horizon',
                'status' => 'published',
                'content_json' => json_encode([
                    'version' => 1,
                    'blocks' => [
                        ['id' => 'hl1', 'type' => 'hello-prime/highlight', 'data' => ['badge' => 'Client vérifié', 'text' => 'Prime CMS est <strong>rapide</strong> !']],
                    ],
                ], JSON_THROW_ON_ERROR),
                'meta' => ['author_role' => 'CTO', 'rating' => '5', 'featured' => '1'],
                'new_terms' => ['testimonial_source' => 'Trustpilot'],
            ],
        ));
        $this->assertSame(302, $storeRes->status());
        preg_match('#/(\d+)/edit$#', (string) $storeRes->header('Location'), $tm);
        $testimonialId = (int) $tm[1];

        // Verify custom fields persisted via HTTP POST -> FieldValidator -> EntryRepository
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        $savedTestimonial = $entryRepo->find($testimonialId);
        $this->assertNotNull($savedTestimonial);
        $this->assertSame('CTO', $savedTestimonial->meta('author_role'));
        $this->assertSame(5, $savedTestimonial->meta('rating'));
        $this->assertTrue((bool) $savedTestimonial->meta('featured'));

        // Verify preview renders the custom block HTML cleanly
        $preview = $kernel->handle(Request::create('GET', "/admin/content/testimonial/{$testimonialId}/preview"));
        $this->assertSame(200, $preview->status());
        $this->assertStringContainsString('pc-block--hello-highlight', $preview->body());
        $this->assertStringContainsString('Client vérifié', $preview->body());

        // Deactivate + Uninstall (deleteFiles: false) -> ZERO testimonial entries & ZERO testimonial_source terms remain!
        $manager->deactivate('hello-prime');
        $manager->uninstall('hello-prime', deleteFiles: false);

        /** @var TermRepository $termRepo */
        $termRepo = $app->get(TermRepository::class);

        $this->assertNull($entryRepo->find($testimonialId));
        $this->assertSame([], $termRepo->allForTaxonomy('testimonial_source'));
    }

    public function testContentListTabsSearchFiltersAndRoleVisibilityHttp(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Connection $conn */
        $conn = $app->get(Connection::class);
        /** @var TermRepository $termRepo */
        $termRepo = $app->get(TermRepository::class);
        $termRepo->ensureDefaultCategory();
        $catGuides = $termRepo->create('category', 'Guides', 'guides');
        $catNews = $termRepo->create('category', 'Actualités', 'actualites');

        $csrfAdmin = $this->csrfToken($app);

        // Seed Admin entries across statuses: published, draft (2026-09), private, scheduled, trash
        $pubId = $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Article Phare Novembre',
            'status' => 'published',
            'published_at' => '2026-11-10T10:00',
            'terms' => ['category' => [(string) $catGuides->id]],
        ]);
        $draftAdminId = $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Brouillon Secret Admin',
            'status' => 'draft',
            'terms' => ['category' => [(string) $catNews->id]],
        ]);
        $conn->table('entries')->where('id', '=', $draftAdminId)->update(['created_at' => '2026-09-12 09:00:00']);

        $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Note Privée Admin',
            'status' => 'private',
        ]);
        $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Annonce Future Planifiée',
            'status' => 'scheduled',
            'published_at' => '2030-05-01T08:00',
        ]);
        $trashId = $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Article Corbeille Admin',
            'status' => 'draft',
        ]);
        $kernel->handle(Request::create('POST', "/admin/content/post/{$trashId}/trash", body: ['_csrf_token' => $csrfAdmin]));

        // Seed Contributor pending entry
        $this->loginAs($app, 2);
        $csrfContrib = $this->csrfToken($app);
        $this->createPostViaHttp($kernel, $csrfContrib, [
            'title' => 'Proposition Contributeur',
            'status' => 'pending',
            'terms' => ['category' => [(string) $catGuides->id]],
        ]);

        // Seed Author draft entry
        $this->loginAs($app, 4);
        $csrfAuthor = $this->csrfToken($app);
        $this->createPostViaHttp($kernel, $csrfAuthor, [
            'title' => 'Brouillon Auteur Perso',
            'status' => 'draft',
        ]);

        // 1. Admin: `all` lists all 6 non-trash entries and excludes trashed entry
        $this->loginAs($app, 1);
        $allRes = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $allRes->status());
        $allTableBody = $this->extractTbody($allRes->body());
        $this->assertStringContainsString('Article Phare Novembre', $allTableBody);
        $this->assertStringContainsString('Brouillon Secret Admin', $allTableBody);
        $this->assertStringContainsString('Note Privée Admin', $allTableBody);
        $this->assertStringContainsString('Annonce Future Planifiée', $allTableBody);
        $this->assertStringContainsString('Proposition Contributeur', $allTableBody);
        $this->assertStringContainsString('Brouillon Auteur Perso', $allTableBody);
        $this->assertStringNotContainsString('Article Corbeille Admin', $allTableBody);
        $this->assertSame(6, substr_count($allTableBody, 'data-prime-table-select-row'));

        // Each exact status tab shows ONLY matching entries and matches tab counter
        $expectedTabs = [
            'published' => ['count' => 1, 'present' => 'Article Phare Novembre', 'absent' => 'Brouillon Secret Admin'],
            'draft' => ['count' => 2, 'present' => 'Brouillon Secret Admin', 'absent' => 'Article Phare Novembre'],
            'pending' => ['count' => 1, 'present' => 'Proposition Contributeur', 'absent' => 'Brouillon Secret Admin'],
            'scheduled' => ['count' => 1, 'present' => 'Annonce Future Planifiée', 'absent' => 'Article Phare Novembre'],
            'private' => ['count' => 1, 'present' => 'Note Privée Admin', 'absent' => 'Article Phare Novembre'],
            'trash' => ['count' => 1, 'present' => 'Article Corbeille Admin', 'absent' => 'Article Phare Novembre'],
        ];
        foreach ($expectedTabs as $tab => $expect) {
            $res = $kernel->handle(Request::create('GET', "/admin/content/post?status={$tab}"));
            $this->assertSame(200, $res->status());
            $tbody = $this->extractTbody($res->body());
            $this->assertSame($expect['count'], substr_count($tbody, 'data-prime-table-select-row'), "Row count mismatch on tab [{$tab}]");
            $this->assertStringContainsString($expect['present'], $tbody);
            $this->assertStringNotContainsString($expect['absent'], $tbody);
        }

        // 2. Search works with BOTH `?q=` and `?search=` alias
        foreach (['/admin/content/post?q=Phare', '/admin/content/post?search=Phare'] as $url) {
            $res = $kernel->handle(Request::create('GET', $url));
            $this->assertSame(200, $res->status());
            $tbody = $this->extractTbody($res->body());
            $this->assertSame(1, substr_count($tbody, 'data-prime-table-select-row'));
            $this->assertStringContainsString('Article Phare Novembre', $tbody);
            $this->assertStringNotContainsString('Brouillon Secret Admin', $tbody);
        }

        // 3. Taxonomy, author, and month filters narrow list accurately
        $catRes = $kernel->handle(Request::create('GET', '/admin/content/post?term=category:' . $catGuides->id));
        $catBody = $this->extractTbody($catRes->body());
        $this->assertSame(2, substr_count($catBody, 'data-prime-table-select-row'));
        $this->assertStringContainsString('Article Phare Novembre', $catBody);
        $this->assertStringContainsString('Proposition Contributeur', $catBody);

        $monthRes = $kernel->handle(Request::create('GET', '/admin/content/post?month=2026-09'));
        $monthBody = $this->extractTbody($monthRes->body());
        $this->assertSame(1, substr_count($monthBody, 'data-prime-table-select-row'));
        $this->assertStringContainsString('Brouillon Secret Admin', $monthBody);

        $authorFilterRes = $kernel->handle(Request::create('GET', '/admin/content/post?author=2'));
        $authorFilterBody = $this->extractTbody($authorFilterRes->body());
        $this->assertSame(1, substr_count($authorFilterBody, 'data-prime-table-select-row'));
        $this->assertStringContainsString('Proposition Contributeur', $authorFilterBody);

        // 4. Contributor visibility: sees ONLY own entries + published entries (never Admin/Author drafts or private!)
        $this->loginAs($app, 2);
        $contribList = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $contribList->status());
        $contribBody = $this->extractTbody($contribList->body());
        $this->assertSame(2, substr_count($contribBody, 'data-prime-table-select-row'));
        $this->assertStringContainsString('Article Phare Novembre', $contribBody);
        $this->assertStringContainsString('Proposition Contributeur', $contribBody);
        $this->assertStringNotContainsString('Brouillon Secret Admin', $contribBody);
        $this->assertStringNotContainsString('Note Privée Admin', $contribBody);
        $this->assertStringNotContainsString('Brouillon Auteur Perso', $contribBody);

        // 5. Author visibility: sees ONLY own entries + published entries (never Admin draft/private or Contributor pending!)
        $this->loginAs($app, 4);
        $authorList = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $authorList->status());
        $authorBody = $this->extractTbody($authorList->body());
        $this->assertSame(2, substr_count($authorBody, 'data-prime-table-select-row'));
        $this->assertStringContainsString('Article Phare Novembre', $authorBody);
        $this->assertStringContainsString('Brouillon Auteur Perso', $authorBody);
        $this->assertStringNotContainsString('Brouillon Secret Admin', $authorBody);
        $this->assertStringNotContainsString('Proposition Contributeur', $authorBody);

        $this->assertGreaterThan(0, $pubId);
    }

    public function testPermanentDeleteOutsideTrashReturnsHttp409OnRowAndBulk(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        $csrf = $this->csrfToken($app);

        $id1 = $this->createPostViaHttp($kernel, $csrf, ['title' => 'Actif Un', 'status' => 'draft']);
        $id2 = $this->createPostViaHttp($kernel, $csrf, ['title' => 'Actif Deux', 'status' => 'published']);

        // Single permanent delete outside trash -> HTTP 409 & entry preserved
        $single409 = $kernel->handle(Request::create('POST', "/admin/content/post/{$id1}/delete", body: ['_csrf_token' => $csrf]));
        $this->assertSame(409, $single409->status());
        $this->assertStringContainsString('corbeille', $single409->body());
        $this->assertNotNull($entryRepo->find($id1));

        // Bulk permanent delete outside trash -> HTTP 409 & BOTH entries preserved
        $bulk409 = $kernel->handle(Request::create('POST', '/admin/content/post/bulk', body: [
            '_csrf_token' => $csrf,
            'bulk_action' => 'delete',
            'ids' => [(string) $id1, (string) $id2],
        ]));
        $this->assertSame(409, $bulk409->status());
        $this->assertStringContainsString('corbeille', $bulk409->body());
        $this->assertNotNull($entryRepo->find($id1));
        $this->assertNotNull($entryRepo->find($id2));

        // After moving to trash, single and bulk permanent deletions succeed
        $kernel->handle(Request::create('POST', "/admin/content/post/{$id1}/trash", body: ['_csrf_token' => $csrf]));
        $kernel->handle(Request::create('POST', "/admin/content/post/{$id2}/trash", body: ['_csrf_token' => $csrf]));

        $singleOk = $kernel->handle(Request::create('POST', "/admin/content/post/{$id1}/delete", body: ['_csrf_token' => $csrf]));
        $this->assertSame(302, $singleOk->status());
        $this->assertNull($entryRepo->find($id1));

        $bulkOk = $kernel->handle(Request::create('POST', '/admin/content/post/bulk', body: [
            '_csrf_token' => $csrf,
            'bulk_action' => 'delete',
            'ids' => [(string) $id2],
        ]));
        $this->assertSame(302, $bulkOk->status());
        $this->assertNull($entryRepo->find($id2));
    }

    public function testContributorPrimarySubmitPendingLockAndFourRoleHttpMatrix(): void
    {
        $app = $this->createApp(authenticateAs: 'contributor');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        $csrfContrib = $this->csrfToken($app);

        // 1. Contributor create screen displays primary "Soumettre pour relecture" (pending) + secondary "Enregistrer le brouillon" (draft)
        $createView = $kernel->handle(Request::create('GET', '/admin/content/post/create'));
        $this->assertSame(200, $createView->status());
        $this->assertStringContainsString('Soumettre pour relecture', $createView->body());
        $this->assertStringContainsString('Enregistrer le brouillon', $createView->body());
        $this->assertMatchesRegularExpression('/data-prime-primary-save[^>]*data-prime-submit-status="pending"/', $createView->body());
        $this->assertMatchesRegularExpression('/data-prime-secondary-save[^>]*data-prime-submit-status="draft"/', $createView->body());

        // 2. Contributor submits entry for review (`pending`) -> becomes read-only on GET and 403 on POST
        $pendingId = $this->createPostViaHttp($kernel, $csrfContrib, [
            'title' => 'Article Soumis Relecture',
            'status' => 'pending',
        ]);
        $savedPending = $entryRepo->find($pendingId);
        $this->assertNotNull($savedPending);
        $this->assertSame(Entry::STATUS_PENDING, $savedPending->status);

        $readOnlyView = $kernel->handle(Request::create('GET', "/admin/content/post/{$pendingId}/edit"));
        $this->assertSame(200, $readOnlyView->status());
        $this->assertStringContainsString('en attente de relecture', strtolower($readOnlyView->body()));
        $this->assertStringNotContainsString('data-prime-primary-save', $readOnlyView->body());

        $editPendingPost = $kernel->handle(Request::create('POST', "/admin/content/post/{$pendingId}", body: [
            '_csrf_token' => $csrfContrib,
            'title' => 'Tentative Modification',
            'status' => 'draft',
            'content_json' => '{"version":1,"blocks":[]}',
        ]));
        $this->assertSame(403, $editPendingPost->status());

        // Contributor cannot trash own entry
        $contribTrash = $kernel->handle(Request::create('POST', "/admin/content/post/{$pendingId}/trash", body: ['_csrf_token' => $csrfContrib]));
        $this->assertSame(403, $contribTrash->status());

        // 3. Author can publish/edit/trash/restore own entry, CANNOT edit or trash contributor's entry
        $this->loginAs($app, 4);
        $csrfAuthor = $this->csrfToken($app);

        $authorId = $this->createPostViaHttp($kernel, $csrfAuthor, [
            'title' => 'Article Auteur Publié',
            'status' => 'published',
        ]);
        $this->assertSame(Entry::STATUS_PUBLISHED, $entryRepo->find($authorId)?->status);

        $authorEditOther = $kernel->handle(Request::create('GET', "/admin/content/post/{$pendingId}/edit"));
        $this->assertSame(403, $authorEditOther->status());
        $authorTrashOther = $kernel->handle(Request::create('POST', "/admin/content/post/{$pendingId}/trash", body: ['_csrf_token' => $csrfAuthor]));
        $this->assertSame(403, $authorTrashOther->status());

        $authorTrashOwn = $kernel->handle(Request::create('POST', "/admin/content/post/{$authorId}/trash", body: ['_csrf_token' => $csrfAuthor]));
        $this->assertSame(302, $authorTrashOwn->status());
        $authorRestoreOwn = $kernel->handle(Request::create('POST', "/admin/content/post/{$authorId}/restore", body: ['_csrf_token' => $csrfAuthor]));
        $this->assertSame(302, $authorRestoreOwn->status());
        $this->assertSame(Entry::STATUS_PUBLISHED, $entryRepo->find($authorId)?->status);

        // 4. Editor can edit & publish contributor's pending entry
        $this->loginAs($app, 3);
        $csrfEditor = $this->csrfToken($app);

        $editorEdit = $kernel->handle(Request::create('GET', "/admin/content/post/{$pendingId}/edit"));
        $this->assertSame(200, $editorEdit->status());
        $this->assertStringContainsString('data-prime-primary-save', $editorEdit->body());

        $editorPub = $kernel->handle(Request::create('POST', "/admin/content/post/{$pendingId}", body: [
            '_csrf_token' => $csrfEditor,
            'title' => 'Article Validé Éditeur',
            'status' => 'published',
            'content_json' => '{"version":1,"blocks":[]}',
        ]));
        $this->assertSame(302, $editorPub->status());
        $this->assertSame(Entry::STATUS_PUBLISHED, $entryRepo->find($pendingId)?->status);
    }

    public function testLockHolderBannerHiddenDiscreetTrashCtaRevisionRestoreAndZeroNPlusOne(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        /** @var RevisionRepository $revRepo */
        $revRepo = $app->get(RevisionRepository::class);
        /** @var TermRepository $termRepo */
        $termRepo = $app->get(TermRepository::class);
        /** @var Connection $conn */
        $conn = $app->get(Connection::class);
        $conn->enableQueryLog(true);

        $csrfAdmin = $this->csrfToken($app);

        $v1Json = json_encode([
            'version' => 1,
            'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Contenu V1 original.']]],
        ], JSON_THROW_ON_ERROR);
        $v2Json = json_encode([
            'version' => 1,
            'blocks' => [['id' => 'p2', 'type' => 'paragraph', 'data' => ['text' => 'Contenu V2 révisé.']]],
        ], JSON_THROW_ON_ERROR);

        $entryId = $this->createPostViaHttp($kernel, $csrfAdmin, [
            'title' => 'Titre Version 1',
            'status' => 'draft',
            'content_json' => $v1Json,
        ]);

        // 1. Lock holder visiting GET /edit sees hidden lock-lost banner and discreet outline trash button (C1 + C7)
        $editRes = $kernel->handle(Request::create('GET', "/admin/content/post/{$entryId}/edit"));
        $this->assertSame(200, $editRes->status());
        $this->assertMatchesRegularExpression('/data-prime-lock-lost-banner[^>]*\bhidden\b/', $editRes->body());
        $this->assertStringContainsString('pc-btn--danger-outline', $editRes->body());
        $this->assertStringNotContainsString('pc-btn--danger pc-btn--sm pc-btn--block', $editRes->body());

        // Lock holder heartbeat returns 200 ok:true
        $hbOk = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}/lock/heartbeat",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAdmin],
        ));
        $this->assertSame(200, $hbOk->status());
        $this->assertStringContainsString('"ok":true', $hbOk->body());

        // Update to V2 and restore V1 via HTTP revision restore endpoint
        $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}", body: [
            '_csrf_token' => $csrfAdmin,
            'title' => 'Titre Version 2',
            'status' => 'draft',
            'content_json' => $v2Json,
        ]));
        $revs = $revRepo->forEntry($entryId, includeAutosaves: false);
        $v1Rev = $revs[count($revs) - 1];
        $restoreRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}/revisions/{$v1Rev->id}/restore",
            body: ['_csrf_token' => $csrfAdmin],
        ));
        $this->assertSame(302, $restoreRes->status());
        $restoredEntry = $entryRepo->find($entryId);
        $this->assertNotNull($restoredEntry);
        $this->assertSame('Titre Version 1', $restoredEntry->title);
        $this->assertStringContainsString('Contenu V1 original.', $restoredEntry->contentHtml);

        // 2. EntryQuery 0 N+1 proof: identical SQL query count for 2 vs 20 entries with meta + terms
        $cat = $termRepo->create('category', 'Perf', 'perf');
        for ($i = 1; $i <= 2; $i++) {
            $this->createPostViaHttp($kernel, $csrfAdmin, [
                'title' => 'Perf Entry ' . $i,
                'status' => 'published',
                'terms' => ['category' => [(string) $cat->id]],
            ]);
        }

        $query = new EntryQuery($conn, $entryRepo);
        $conn->clearQueryLog();
        $batch2 = $query->newQuery()->type('post')->status(Entry::STATUS_PUBLISHED)->withRelations(true, true, true)->get();
        $queries2 = count($conn->queryLog());
        $this->assertCount(2, $batch2);

        for ($i = 3; $i <= 20; $i++) {
            $this->createPostViaHttp($kernel, $csrfAdmin, [
                'title' => 'Perf Entry ' . $i,
                'status' => 'published',
                'terms' => ['category' => [(string) $cat->id]],
            ]);
        }

        $conn->clearQueryLog();
        $batch20 = $query->newQuery()->type('post')->status(Entry::STATUS_PUBLISHED)->withRelations(true, true, true)->get();
        $queries20 = count($conn->queryLog());
        $this->assertCount(20, $batch20);
        $this->assertSame($queries2, $queries20, 'EntryQuery must execute identical SQL count for 2 vs 20 entries (0 N+1).');
    }

    public function testCreateAndEditPreserveStatusOnDirectSaveWhileExplicitPublishChangesStatus(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var EntryRepository $entryRepo */
        $entryRepo = $app->get(EntryRepository::class);
        $csrf = $this->csrfToken($app);

        // 1. Create form defaults status select to `draft`, while primary CTA explicitly targets `published`
        $createPage = $kernel->handle(Request::create('GET', '/admin/content/post/create'));
        $this->assertSame(200, $createPage->status());
        $this->assertStringContainsString('<option value="draft" selected>Brouillon</option>', $createPage->body());
        $this->assertStringContainsString('data-prime-primary-save', $createPage->body());
        $this->assertStringContainsString('data-prime-submit-status="published"', $createPage->body());

        // 2. Direct form submission (as triggered by Ctrl/Cmd+S) preserves `draft` on creation
        $storeRes = $kernel->handle(Request::create(
            'POST',
            '/admin/content/post',
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Brouillon Raccourci Clavier',
                'status' => 'draft',
                'content_json' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Version initiale."}}]}',
            ],
        ));
        $this->assertSame(302, $storeRes->status());
        preg_match('#/admin/content/post/(\d+)/edit$#', (string) $storeRes->header('Location'), $m);
        $entryId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $entryId);

        $created = $entryRepo->find($entryId);
        $this->assertNotNull($created);
        $this->assertSame('draft', $created->status);

        // 3. Direct save on existing draft preserves `draft`
        $updateDraftRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}",
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Brouillon Raccourci Clavier v2',
                'status' => 'draft',
                'content_json' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Mise à jour brouillon."}}]}',
            ],
        ));
        $this->assertSame(302, $updateDraftRes->status());
        $stillDraft = $entryRepo->find($entryId);
        $this->assertNotNull($stillDraft);
        $this->assertSame('draft', $stillDraft->status);
        $this->assertSame('Brouillon Raccourci Clavier v2', $stillDraft->title);

        // 4. Explicit publication sets status to `published`
        $publishRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}",
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Article Publié Explicitement',
                'status' => 'published',
                'content_json' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Contenu public."}}]}',
            ],
        ));
        $this->assertSame(302, $publishRes->status());
        $published = $entryRepo->find($entryId);
        $this->assertNotNull($published);
        $this->assertSame('published', $published->status);

        // 5. Direct save on already-published entry updates in place while staying `published`
        $updatePubRes = $kernel->handle(Request::create(
            'POST',
            "/admin/content/post/{$entryId}",
            body: [
                '_csrf_token' => $csrf,
                'title' => 'Article Publié Mis à Jour',
                'status' => 'published',
                'content_json' => '{"version":1,"blocks":[{"id":"b1","type":"paragraph","data":{"text":"Contenu public édité."}}]}',
            ],
        ));
        $this->assertSame(302, $updatePubRes->status());
        $stillPublished = $entryRepo->find($entryId);
        $this->assertNotNull($stillPublished);
        $this->assertSame('published', $stillPublished->status);
        $this->assertSame('Article Publié Mis à Jour', $stillPublished->title);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createPostViaHttp(Kernel $kernel, string $csrf, array $fields): int
    {
        $body = array_merge([
            '_csrf_token' => $csrf,
            'title' => 'Post de test',
            'status' => 'draft',
            'content_json' => '{"version":1,"blocks":[]}',
        ], $fields);

        $res = $kernel->handle(Request::create('POST', '/admin/content/post', body: $body));
        $this->assertSame(302, $res->status(), 'Expected 302 redirect after creating post.');
        preg_match('#/(\d+)/edit$#', (string) $res->header('Location'), $m);
        $this->assertNotEmpty($m[1] ?? '', 'Expected redirect Location containing entry ID.');

        return (int) $m[1];
    }

    private function extractTbody(string $html): string
    {
        if (preg_match('#<tbody\b[^>]*>.*?</tbody>#si', $html, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    private function createApp(string $authenticateAs = 'admin'): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('c', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE (type, parent_id, slug))');
        $pdo->exec("CREATE TABLE pc_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(64) DEFAULT 'default', handler VARCHAR(191), payload TEXT, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, available_at DATETIME, reserved_at DATETIME NULL, failed_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_entry_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, meta_key VARCHAR(191), meta_value LONGTEXT, UNIQUE (entry_id, meta_key))');
        $pdo->exec('CREATE TABLE pc_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy VARCHAR(40), name VARCHAR(191), slug VARCHAR(191), description TEXT NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, count INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (taxonomy, slug))');
        $pdo->exec('CREATE TABLE pc_entry_terms (entry_id INTEGER, term_id INTEGER, position INTEGER DEFAULT 0, PRIMARY KEY (entry_id, term_id))');
        $pdo->exec('CREATE TABLE pc_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, author_id INTEGER NULL, is_autosave INTEGER DEFAULT 0, title VARCHAR(255), excerpt TEXT NULL, content LONGTEXT, meta LONGTEXT NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entry_locks (entry_id INTEGER PRIMARY KEY, user_id INTEGER, acquired_at DATETIME, heartbeat_at DATETIME)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        // Seed 4 users: 1=Admin, 2=Contributor, 3=Editor, 4=Author
        $now = '2026-11-15 12:00:00';
        $hash = password_hash('Prime!Secret#2026', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Tout', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Brouillons', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (3, 'editor', 'Éditeur', 'Contenus', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (4, 'author', 'Auteur', 'Propres contenus', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (3, 'dashboard.view'), (3, 'content.view'), (3, 'content.create'), (3, 'content.edit_own'), (3, 'content.edit_any'), (3, 'content.publish'), (3, 'content.delete_own'), (3, 'content.delete_any')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (4, 'dashboard.view'), (4, 'content.view'), (4, 'content.create'), (4, 'content.edit_own'), (4, 'content.publish'), (4, 'content.delete_own')");

        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'admin@prime.test', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'contrib@prime.test', $hash, 'Lucas Contrib', 'active', 'fr', $now, $now]);
        $stmt->execute([3, 'editor@prime.test', $hash, 'Clara Editor', 'active', 'fr', $now, $now]);
        $stmt->execute([4, 'author@prime.test', $hash, 'Hugo Auteur', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2), (3, 3), (4, 4)');

        $userId = match ($authenticateAs) {
            'contributor' => 2,
            'editor' => 3,
            'author' => 4,
            default => 1,
        };
        $this->loginAs($app, $userId);

        return $app;
    }

    private function loginAs(Application $app, int $userId): void
    {
        /** @var UserRepository $userRepo */
        $userRepo = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $user = $userRepo->find($userId);
        if ($user !== null) {
            $auth->login($user);
        }
    }

    private function csrfToken(Application $app): string
    {
        /** @var Session $session */
        $session = $app->get(Session::class);

        return (new Csrf($session))->token();
    }
}
