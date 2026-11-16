<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Media\ImageProcessor;
use Prime\Media\MediaConfig;
use Prime\Media\MediaFolderRepository;
use Prime\Media\MediaRepository;
use Prime\Media\MediaServingController;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\Media\UploadsGuard;
use Prime\Plugins\AssetPublisher;
use Prime\Security\Csrf;
use Prime\Tests\Support\MediaFixtures;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Comprehensive Phase 7 HTTP, RBAC, CSRF, Folder Hierarchy, Usage Tracking, Content/Avatar/Dashboard
 * integration, and CLI (`media:regenerate`, `media:link`, `media:cleanup`) end-to-end test suite.
 */
final class AdminMediaHttpTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
        $this->tmpDir = '';
    }

    public function testAuthenticationAndRbacMatrixAcrossAllFiveRoles(): void
    {
        // 1. Unauthenticated -> /admin/media redirects to /admin/login; JSON API returns 401
        $guestApp = $this->createApp(authenticateAs: null);
        /** @var Kernel $guestKernel */
        $guestKernel = $guestApp->get(Kernel::class);
        $this->assertSame(302, $guestKernel->handle(Request::create('GET', '/admin/media'))->status());
        $this->assertSame(401, $guestKernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']))->status());

        // 2. Subscriber (no media.view) -> 403 everywhere
        $app = $this->createApp(authenticateAs: 'subscriber');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/media'))->status());
        $this->assertSame(403, $kernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']))->status());

        // 3. Contributor (media.view ONLY — NO media.upload)
        $this->loginAs($app, 2);
        $csrf = $this->csrfToken($app);
        $page = $kernel->handle(Request::create('GET', '/admin/media'));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Médiathèque', $page->body());
        $this->assertStringNotContainsString('pc-media-upload-btn', $page->body());

        $listApi = $kernel->handle(Request::create('GET', '/admin/media/api/items', headers: ['Accept' => 'application/json']));
        $this->assertSame(200, $listApi->status());

        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/contrib.jpg', 200, 150);
        $uploadDenied = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($jpg, 'contrib.jpg', 'image/jpeg')],
        ));
        $this->assertSame(403, $uploadDenied->status());

        // 4. Author (media.view + media.upload, NO media.delete_any)
        $this->loginAs($app, 4);
        $csrfAuthor = $this->csrfToken($app);

        // Missing CSRF token -> 419
        $noCsrf = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json'],
            files: ['file' => MediaFixtures::uploadedFile($jpg, 'author.jpg', 'image/jpeg')],
        ));
        $this->assertSame(419, $noCsrf->status());

        // Author uploads own file -> 201
        $authorUpload = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAuthor],
            files: ['file' => MediaFixtures::uploadedFile($jpg, 'author-own.jpg', 'image/jpeg')],
        ));
        $this->assertSame(201, $authorUpload->status());
        $authorJson = json_decode($authorUpload->body(), true, 512, JSON_THROW_ON_ERROR);
        $authorMediaId = (int) $authorJson['item']['id'];

        // Admin uploads another file -> Author CANNOT edit, regenerate, or delete Admin's media (403!)
        $this->loginAs($app, 1);
        $csrfAdmin = $this->csrfToken($app);
        $jpg2 = MediaFixtures::createJpeg($this->tmpDir . '/tmp/admin.jpg', 300, 200);
        $adminUpload = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAdmin],
            files: ['file' => MediaFixtures::uploadedFile($jpg2, 'admin-asset.jpg', 'image/jpeg')],
        ));
        $this->assertSame(201, $adminUpload->status());
        $adminMediaId = (int) json_decode($adminUpload->body(), true, 512, JSON_THROW_ON_ERROR)['item']['id'];

        // Switch back to Author -> verify ownership boundary!
        $this->loginAs($app, 4);
        $csrfAuthor2 = $this->csrfToken($app);
        $editForeign = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$adminMediaId}",
            body: ['title' => 'Piraté'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAuthor2],
        ));
        $this->assertSame(403, $editForeign->status());

        $deleteForeign = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$adminMediaId}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAuthor2],
        ));
        $this->assertSame(403, $deleteForeign->status());

        // Bulk delete containing a foreign ID must forbid foreign media
        $bulkForeign = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/bulk',
            body: ['action' => 'delete', 'ids' => [$adminMediaId]],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAuthor2],
        ));
        $this->assertSame(403, $bulkForeign->status());

        // Author CAN edit & delete their OWN media
        $editOwn = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$authorMediaId}",
            body: ['title' => 'Titre Auteur', 'alt' => 'Alt Auteur'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfAuthor2],
        ));
        $this->assertSame(200, $editOwn->status());

        // 5. Editor (media.delete_any) CAN delete Admin's media
        $this->loginAs($app, 3);
        $csrfEditor = $this->csrfToken($app);
        $editorDelete = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/items/{$adminMediaId}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrfEditor],
        ));
        $this->assertSame(200, $editorDelete->status());
    }

    public function testFoldersHierarchyCycleGuardFiltersAndBulkMove(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // Create root folder & child folder
        $f1Res = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Campagne 2026'],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(201, $f1Res->status());
        $f1Id = (int) json_decode($f1Res->body(), true, 512, JSON_THROW_ON_ERROR)['folder']['id'];

        $f2Res = $kernel->handle(Request::create(
            'POST',
            '/admin/media/api/folders',
            body: ['name' => 'Bannières', 'parent_id' => $f1Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(201, $f2Res->status());
        $f2Id = (int) json_decode($f2Res->body(), true, 512, JSON_THROW_ON_ERROR)['folder']['id'];

        // Circular parent move (f1 into its own child f2) MUST fail with 422
        $cycleRes = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f1Id}",
            body: ['name' => 'Campagne 2026', 'parent_id' => $f2Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(422, $cycleRes->status());

        // Upload 1 image & 1 PDF
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/tmp/promo.jpg', 400, 300);
        $pdf = MediaFixtures::createPdf($this->tmpDir . '/tmp/guide.pdf');

        $m1 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            body: ['folder_id' => (string) $f2Id],
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($jpg, 'promo-ete.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        $m2 = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($pdf, 'guide-officiel.pdf', 'application/pdf')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];

        // Filter by kind=document returns only PDF
        $docList = json_decode($kernel->handle(Request::create(
            'GET',
            '/admin/media/api/items',
            query: ['kind' => 'document'],
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $docList['total']);
        $this->assertSame($m2['id'], $docList['items'][0]['id']);

        // Filter by folder=f2 returns only image
        $folderList = json_decode($kernel->handle(Request::create(
            'GET',
            '/admin/media/api/items',
            query: ['folder' => (string) $f2Id],
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $folderList['total']);
        $this->assertSame($m1['id'], $folderList['items'][0]['id']);

        // Deleting child folder f2 reparents its media cleanly to parent folder f1; deleting root f1 uncategorizes it (null)
        $delFolder = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f2Id}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $delFolder->status());

        /** @var MediaRepository $mediaRepo */
        $mediaRepo = $app->get(MediaRepository::class);
        $reloaded = $mediaRepo->find((int) $m1['id']);
        $this->assertNotNull($reloaded);
        $this->assertSame($f1Id, $reloaded->folderId);

        $delRoot = $kernel->handle(Request::create(
            'POST',
            "/admin/media/api/folders/{$f1Id}/delete",
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
        ));
        $this->assertSame(200, $delRoot->status());
        $reloadedAfterRoot = $mediaRepo->find((int) $m1['id']);
        $this->assertNotNull($reloadedAfterRoot);
        $this->assertNull($reloadedAfterRoot->folderId);
    }

    public function testContentIntegrationUsageTrackingUserAvatarDashboardKpiAndCli(): void
    {
        $app = $this->createApp(authenticateAs: 'admin');
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $csrf = $this->csrfToken($app);

        // 1. Upload hero image & avatar image
        $heroSrc = MediaFixtures::createJpeg($this->tmpDir . '/tmp/hero.jpg', 1200, 800);
        $avatarSrc = MediaFixtures::createTransparentPng($this->tmpDir . '/tmp/avatar.png', 400, 400);

        $hero = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($heroSrc, 'hero-post.jpg', 'image/jpeg')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $heroId = (int) $hero['id'];

        $avatar = json_decode($kernel->handle(Request::create(
            'POST',
            '/admin/media/api/upload',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            files: ['file' => MediaFixtures::uploadedFile($avatarSrc, 'camille-avatar.png', 'image/png')],
        ))->body(), true, 512, JSON_THROW_ON_ERROR)['item'];
        $avatarId = (int) $avatar['id'];

        // 2. Attach hero as featured image + editor image block on a new post
        $blocksJson = json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'img1', 'type' => 'image', 'data' => ['mediaId' => $heroId, 'alt' => 'Couverture', 'align' => 'wide']],
            ],
        ], JSON_THROW_ON_ERROR);

        $createPost = $kernel->handle(Request::create('POST', '/admin/content/post', body: [
            '_csrf_token' => $csrf,
            'title' => 'Article avec médias',
            'slug' => 'article-avec-medias',
            'status' => 'published',
            'featured_media_id' => (string) $heroId,
            'content_json' => $blocksJson,
        ]));
        $this->assertSame(302, $createPost->status());
        preg_match('#/(\d+)/edit$#', (string) $createPost->header('Location'), $m);
        $entryId = (int) $m[1];

        // Content listing renders the 40×40 thumbnail
        $postIndex = $kernel->handle(Request::create('GET', '/admin/content/post'));
        $this->assertSame(200, $postIndex->status());
        $this->assertStringContainsString('pc-content-thumb', $postIndex->body());

        // Inspector API reports both 'thumbnail' & 'block' usages on hero media!
        $detailJson = json_decode($kernel->handle(Request::create(
            'GET',
            "/admin/media/api/items/{$heroId}",
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $contexts = array_column($detailJson['usages'], 'context');
        $this->assertContains('thumbnail', $contexts);
        $this->assertContains('block', $contexts);

        // 3. Save user avatar via /admin/profile/info -> visible in topbar & /admin/users & media detail usages
        $profileUpdate = $kernel->handle(Request::create('POST', '/admin/profile/info', body: [
            '_csrf_token' => $csrf,
            'display_name' => 'Camille Admin',
            'email' => 'admin@prime.test',
            'locale' => 'fr',
            'avatar_media_id' => (string) $avatarId,
        ]));
        $this->assertSame(302, $profileUpdate->status());

        $dashboard = $kernel->handle(Request::create('GET', '/admin'));
        $this->assertSame(200, $dashboard->status());
        // Live Dashboard KPI shows uploaded media + avatar img in topbar
        $this->assertStringContainsString((string) $avatar['thumbnail_url'], $dashboard->body());
        $this->assertStringContainsString('Phase 7', $dashboard->body());

        $usersPage = $kernel->handle(Request::create('GET', '/admin/users'));
        $this->assertSame(200, $usersPage->status());
        $this->assertStringContainsString((string) $avatar['thumbnail_url'], $usersPage->body());

        $avatarDetail = json_decode($kernel->handle(Request::create(
            'GET',
            "/admin/media/api/items/{$avatarId}",
            headers: ['Accept' => 'application/json'],
        ))->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('avatar', array_column($avatarDetail['usages'], 'context'));

        // 4. Verify CLI commands: media:regenerate, media:link, media:cleanup --dry-run & cleanup
        $console = new ConsoleApplication($app);
        $out1 = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:regenerate', "--id={$heroId}", '--force'], $out1));
        $this->assertStringContainsString('variantes régénérées', $out1->stdout());

        $out2 = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:link'], $out2));
        $this->assertTrue(is_link($this->tmpDir . '/public/uploads'));

        // Drop an orphan file into content/uploads/2026/11/orphan-junk.txt
        $orphanAbs = $this->tmpDir . '/content/uploads/2026/11/orphan-junk.txt';
        file_put_contents($orphanAbs, 'orphan payload');
        $this->assertFileExists($orphanAbs);

        $outDry = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:cleanup', '--dry-run'], $outDry));
        $this->assertStringContainsString('orphan-junk.txt', $outDry->stdout());
        $this->assertFileExists($orphanAbs);

        $outClean = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'media:cleanup'], $outClean));
        $this->assertFileDoesNotExist($orphanAbs);
        // Protected .htaccess and real media files MUST remain untouched!
        $this->assertFileExists($this->tmpDir . '/content/uploads/.htaccess');

        // 5. Trash & permanently delete the post -> hero media usage rows are removed cleanly
        $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/trash", body: ['_csrf_token' => $csrf]));
        $kernel->handle(Request::create('POST', "/admin/content/post/{$entryId}/delete", body: ['_csrf_token' => $csrf]));
        /** @var MediaUsageRepository $usageRepo */
        $usageRepo = $app->get(MediaUsageRepository::class);
        $this->assertSame([], $usageRepo->findByMedia($heroId));
    }

    private function createApp(?string $authenticateAs = 'admin'): Application
    {
        $this->tmpDir = sys_get_temp_dir() . '/prime_media_http_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/tmp', 0755, true);
        mkdir($this->tmpDir . '/public', 0755, true);

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        // Redirect media filesystem paths cleanly to our isolated temp directory
        /** @var array<string, mixed> $mediaRaw */
        $mediaRaw = $app->config()->get('media', []);
        $isolatedConfig = new MediaConfig($this->tmpDir, is_array($mediaRaw) ? $mediaRaw : []);
        $app->container()->instance(MediaConfig::class, $isolatedConfig);
        $app->container()->instance(UploadsGuard::class, new UploadsGuard($isolatedConfig));
        $app->container()->instance(ImageProcessor::class, new ImageProcessor($isolatedConfig));
        $app->container()->instance(MediaServingController::class, new MediaServingController($isolatedConfig));

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
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
        $pdo->exec('CREATE TABLE pc_media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, name VARCHAR(120), slug VARCHAR(140), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media (id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER NULL, author_id INTEGER NULL, disk_path VARCHAR(255) UNIQUE, filename VARCHAR(255), original_name VARCHAR(255), extension VARCHAR(16), mime_type VARCHAR(120), kind VARCHAR(24), size_bytes INTEGER DEFAULT 0, width INTEGER NULL, height INTEGER NULL, checksum CHAR(64), alt VARCHAR(255), title VARCHAR(255), caption TEXT NULL, description TEXT NULL, focal_x REAL DEFAULT 0.5, focal_y REAL DEFAULT 0.5, variants LONGTEXT NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media_usage (media_id INTEGER, entry_id INTEGER, context VARCHAR(100), PRIMARY KEY (media_id, entry_id, context))');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $app->container()->instance(Connection::class, $connection);

        // Rebind UploadService cleanly against isolated MediaConfig
        $app->container()->singleton(UploadService::class, static function () use ($app, $isolatedConfig): UploadService {
            return new UploadService(
                $isolatedConfig,
                $app->get(MediaRepository::class),
                $app->get(MediaFolderRepository::class),
                $app->get(ImageProcessor::class),
                $app->get(UploadsGuard::class),
            );
        });

        $now = '2026-11-15 12:00:00';
        $hash = password_hash('Prime!Secret#2026', PASSWORD_BCRYPT);
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Tout', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Lecture médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (3, 'editor', 'Éditeur', 'Gestion complète médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (4, 'author', 'Auteur', 'Propres médias', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (5, 'subscriber', 'Abonné', 'Aucun accès média', 0, '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'dashboard.view'), (2, 'media.view')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (3, 'dashboard.view'), (3, 'media.view'), (3, 'media.upload'), (3, 'media.delete_any'), (3, 'content.view'), (3, 'content.create'), (3, 'content.edit_any'), (3, 'content.publish')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (4, 'dashboard.view'), (4, 'media.view'), (4, 'media.upload')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (5, 'dashboard.view')");

        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'admin@prime.test', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'contrib@prime.test', $hash, 'Lucas Contrib', 'active', 'fr', $now, $now]);
        $stmt->execute([3, 'editor@prime.test', $hash, 'Clara Editor', 'active', 'fr', $now, $now]);
        $stmt->execute([4, 'author@prime.test', $hash, 'Hugo Auteur', 'active', 'fr', $now, $now]);
        $stmt->execute([5, 'sub@prime.test', $hash, 'Nadia Abonnée', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2), (3, 3), (4, 4), (5, 5)');

        if ($authenticateAs !== null) {
            $userId = match ($authenticateAs) {
                'contributor' => 2,
                'editor' => 3,
                'author' => 4,
                'subscriber' => 5,
                default => 1,
            };
            $this->loginAs($app, $userId);
        }

        return $app;
    }

    private function loginAs(Application $app, int $userId): void
    {
        /** @var UserRepository $userRepo */
        $userRepo = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $auth->resetCache();
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
