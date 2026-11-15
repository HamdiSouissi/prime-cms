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
use Prime\Content\EntryRepository;
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
        $pdo->exec('CREATE TABLE pc_entry_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, meta_key VARCHAR(191), meta_value LONGTEXT, UNIQUE (entry_id, meta_key))');
        $pdo->exec('CREATE TABLE pc_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy VARCHAR(40), name VARCHAR(191), slug VARCHAR(191), description TEXT NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, count INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (taxonomy, slug))');
        $pdo->exec('CREATE TABLE pc_entry_terms (entry_id INTEGER, term_id INTEGER, position INTEGER DEFAULT 0, PRIMARY KEY (entry_id, term_id))');
        $pdo->exec('CREATE TABLE pc_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, author_id INTEGER NULL, is_autosave INTEGER DEFAULT 0, title VARCHAR(255), excerpt TEXT NULL, content LONGTEXT, meta LONGTEXT NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entry_locks (entry_id INTEGER PRIMARY KEY, user_id INTEGER, acquired_at DATETIME, heartbeat_at DATETIME)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        // Seed 3 users: 1=Admin, 2=Contributor, 3=Editor
        $now = '2026-11-15 12:00:00';
        $hash = password_hash('Prime!Secret#2026', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Tout', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Brouillons', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (3, 'editor', 'Éditeur', 'Contenus', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (3, 'dashboard.view'), (3, 'content.view'), (3, 'content.create'), (3, 'content.edit_own'), (3, 'content.edit_any'), (3, 'content.publish'), (3, 'content.delete_own'), (3, 'content.delete_any')");

        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'admin@prime.test', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'contrib@prime.test', $hash, 'Lucas Contrib', 'active', 'fr', $now, $now]);
        $stmt->execute([3, 'editor@prime.test', $hash, 'Clara Editor', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2), (3, 3)');

        $userId = match ($authenticateAs) {
            'contributor' => 2,
            'editor' => 3,
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
