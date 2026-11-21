<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\Auth;
use Prime\Auth\RoleRepository;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Cache\PageCache;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Demo\DemoContent;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Media\MediaConfig;
use Prime\Plugins\AssetPublisher;
use Prime\Settings\Settings;
use Prime\Site\Menu\MenuRepository;

/**
 * Phase 12 on a real database: demonstration content installed then removed without trace,
 * and SQL query counts that do not grow with the number of entries (no N+1) and stay within
 * the budget of 25 queries per admin page.
 */
#[Group('database')]
final class PerformanceAndDemoDatabaseTest extends DatabaseTestCase
{
    private string $uploads = '';

    protected function tearDown(): void
    {
        if ($this->uploads !== '') {
            AssetPublisher::deleteDirectory($this->uploads);
        }
        parent::tearDown();
    }

    public function testDemoContentIsInstalledThenRemovedWithoutTrace(): void
    {
        [$app] = $this->boot();
        $menus = $app->get(MenuRepository::class);
        $ownMenu = $menus->create('Mon menu', 'primary');
        $settings = $app->get(Settings::class);
        $before = $this->snapshot();
        $files = $this->files();

        $demo = DemoContent::fromApplication($app);
        $result = $demo->install(posts: 6, mediaCount: 3, termCount: 8);

        $this->assertSame(['entries' => 9, 'terms' => 8, 'media' => 3, 'menus' => 2], $result);
        $this->assertTrue($demo->isInstalled());
        $this->assertSame('page', $settings->get('reading.front_mode'));
        $this->assertNull($menus->find($ownMenu)['location'] ?? null, 'le menu existant cède son emplacement');
        $this->assertGreaterThan(count($files), count($this->files()), 'les images générées sont enregistrées');

        $home = $app->get(Kernel::class)->handle(Request::create('GET', '/', headers: ['Accept' => 'text/html']));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('Un site prêt à accueillir vos contenus', $home->body());
        $this->assertStringContainsString('>Contact</a>', $home->body(), 'le menu de démonstration occupe l’emplacement principal');

        $this->expectExceptionMessageMatches('/déjà installé/');
        try {
            $demo->install();
        } finally {
            $demo->remove();

            $this->assertFalse($demo->isInstalled());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($files, $this->files(), 'les fichiers des images sont supprimés');
            $this->assertSame('primary', $menus->find($ownMenu)['location'] ?? null, 'le menu existant retrouve son emplacement');
            $this->assertNotSame('page', $settings->get('reading.front_mode'));
        }
    }

    public function testQueryCountsDoNotGrowWithTheNumberOfEntries(): void
    {
        [$app, $admin] = $this->boot();
        $kernel = $app->get(Kernel::class);
        $pageCache = $app->get(PageCache::class);
        $pageCache->setEnabled(false);
        $app->get(Auth::class)->login($admin);

        $demo = DemoContent::fromApplication($app);
        $demo->install(posts: 5, mediaCount: 2, termCount: 4);
        // A post in the middle: it has a previous and a next post in both measurements.
        $slug = (string) ($this->connection->table('entries')->where('type', '=', 'post')->orderBy('published_at', 'DESC')->limit(1)->offset(2)->get()[0]['slug'] ?? '');
        $category = (string) $this->connection->table('terms')->where('taxonomy', '=', 'category')->orderBy('id', 'DESC')->value('slug');
        $pages = ['/', '/blog', '/blog/' . $slug, '/categorie/' . $category, '/page-qui-n-existe-pas', '/api/v1/content/post', '/admin', '/admin/content/post', '/admin/media'];

        $small = $this->countQueries($kernel, $pages);

        $entries = $app->get(EntryService::class);
        $categoryId = (int) $this->connection->table('terms')->where('slug', '=', $category)->value('id');
        $mediaId = (int) $this->connection->table('media')->value('id');
        for ($i = 0; $i < 25; ++$i) {
            $entries->create('post', [
                'title' => 'Volume ' . $i,
                'status' => Entry::STATUS_PUBLISHED,
                'content' => '{"version":1,"blocks":[{"id":"p","type":"paragraph","data":{"text":"Texte"}}]}',
                'published_at' => sprintf('2026-01-%02d 10:00:00', ($i % 28) + 1),
                'featured_media_id' => $mediaId,
                'terms' => ['category' => [$categoryId]],
            ], $admin);
        }
        $large = $this->countQueries($kernel, $pages);

        $this->assertSame($small, $large, 'le nombre de requêtes ne doit pas dépendre du nombre de contenus');
        foreach (['/admin', '/admin/content/post', '/admin/media'] as $page) {
            $this->assertLessThanOrEqual(25, $large[$page], $page . ' dépasse 25 requêtes SQL');
        }

        $notFound = $kernel->handle(Request::create('GET', '/page-qui-n-existe-pas', headers: ['Accept' => 'text/html']));
        $this->assertSame(404, $notFound->status());
        $this->assertSame(3, substr_count($notFound->body(), '<article class="aurora-card">'), 'la page 404 montre 3 articles récents, pas tous');

        $demo->remove();
    }

    /**
     * @param list<string> $pages
     * @return array<string, int>
     */
    private function countQueries(Kernel $kernel, array $pages): array
    {
        $counts = [];
        foreach ($pages as $page) {
            $kernel->handle(Request::create('GET', $page, headers: ['Accept' => 'text/html']));
            $this->connection->enableQueryLog(true);
            $this->connection->clearQueryLog();
            $response = $kernel->handle(Request::create('GET', $page, headers: ['Accept' => 'text/html']));
            $counts[$page] = count($this->connection->queryLog());
            $this->connection->enableQueryLog(false);
            $this->assertContains($response->status(), [200, 404], $page);
        }

        return $counts;
    }

    /**
     * @return array{0: Application, 1: User}
     */
    private function boot(): array
    {
        $schema = new Schema($this->connection);
        (new Migrator($this->connection, $schema, [dirname(__DIR__, 2) . '/database/migrations']))->run();

        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', false);
        $app->config()->set('app.url', 'https://prime-perf.test');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('p', 32)));
        $app->config()->set('cache.page.directory', sys_get_temp_dir() . '/prime_perf_pages_' . uniqid('', true));
        $app->boot();
        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->container()->instance(Connection::class, $this->connection);

        $this->uploads = sys_get_temp_dir() . '/prime_perf_uploads_' . bin2hex(random_bytes(4));
        mkdir($this->uploads . '/content/uploads', 0755, true);
        /** @var array<string, mixed> $media */
        $media = require dirname(__DIR__, 2) . '/config/media.php';
        $app->container()->instance(MediaConfig::class, new MediaConfig($this->uploads, $media));

        $role = $app->get(RoleRepository::class)->findBySlug('administrator');
        $this->assertNotNull($role);
        $admin = $app->get(UserRepository::class)->create('perf@prime.test', 'Admin Perf', 'Secret!1234#Prime', [$role->id]);

        return [$app, $app->get(UserRepository::class)->find($admin->id) ?? $admin];
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach (['entries', 'entry_meta', 'entry_terms', 'terms', 'media', 'media_usage', 'menus', 'menu_items', 'revisions'] as $table) {
            $out[$table] = $this->connection->table($table)->count();
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $root = $this->uploads . '/content/uploads';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() !== '.htaccess') {
                $files[] = substr($file->getPathname(), strlen($root));
            }
        }
        sort($files);

        return $files;
    }
}
