<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Cache\CacheManager;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;

/**
 * « Réglages › Performance » screen and its two actions, exercised over the real HTTP kernel
 * against a persistent SQLite fixture (so the settings survive between requests).
 */
final class AdminPerformanceTest extends TestCase
{
    private string $pageCacheDir;

    private Application $app;

    private string $databaseFile;

    protected function setUp(): void
    {
        $this->pageCacheDir = sys_get_temp_dir() . '/prime_perf_pages_' . uniqid('', true);
        $this->databaseFile = sys_get_temp_dir() . '/prime_perf_db_' . uniqid('', true) . '.sqlite';
        $this->app = $this->createApp();
    }

    protected function tearDown(): void
    {
        $entries = is_dir($this->pageCacheDir) ? scandir($this->pageCacheDir) : [];
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->pageCacheDir . '/' . $entry);
            }
        }
        @rmdir($this->pageCacheDir);
        @unlink($this->databaseFile);
    }

    public function testScreenShowsDriverStatisticsAndThePurgeButton(): void
    {
        $body = $this->kernel()->handle(Request::create('GET', '/admin/settings/performance'))->body();

        $this->assertStringContainsString('Performance', $body);
        $this->assertStringContainsString('Pilote actif', $body);
        $this->assertStringContainsString('Pages en cache', $body);
        $this->assertStringContainsString('Dernier vidage', $body);
        $this->assertStringContainsString('Vider tout le cache', $body);
        $this->assertStringContainsString('action="/admin/settings/performance/purge"', $body);
    }

    public function testSavingTheFormDisablesThePageCacheAndAppliesTheTtl(): void
    {
        $this->kernel()->handle($this->post('/admin/settings/performance', ['page_ttl' => '900']));

        $cache = $this->app->get(CacheManager::class);

        $this->assertFalse($cache->pages()->isEnabled());
        $this->assertSame(900, $cache->pages()->defaultTtl());
    }

    public function testAnOutOfRangeTtlIsRejectedWithAnAlertAndNothingIsSaved(): void
    {
        $cache = $this->app->get(CacheManager::class);
        $before = $cache->pages()->defaultTtl();

        $response = $this->kernel()->handle($this->post('/admin/settings/performance', ['page_enabled' => '1', 'page_ttl' => '5']));

        $this->assertSame(302, $response->status());
        $this->assertSame($before, $cache->pages()->defaultTtl());
        $this->assertTrue($cache->pages()->isEnabled(), 'A rejected TTL must not disable the cache.');

        $body = $this->kernel()->handle(Request::create('GET', '/admin/settings/performance'))->body();
        $this->assertStringContainsString('doit être comprise entre 60 et 86 400 secondes', $body);
    }

    public function testPurgeActionEmptiesBothCachesAndReportsIt(): void
    {
        $cache = $this->app->get(CacheManager::class);
        $cache->data()->set('menus:primary', ['items' => 1], 300);
        $cache->pages()->store(Request::create('GET', '/'), \Prime\Http\Response::html('<h1>Accueil</h1>'));

        $this->kernel()->handle($this->post('/admin/settings/performance/purge'));

        $this->assertNull($cache->data()->get('menus:primary'));
        $this->assertSame(0, $cache->pages()->stats()['entries']);

        $body = $this->kernel()->handle(Request::create('GET', '/admin/settings/performance'))->body();
        $this->assertStringContainsString('Tout le cache a été vidé', $body);
    }

    private function post(string $path, array $body = []): Request
    {
        return Request::create('POST', $path, body: $body + [
            \Prime\Security\Csrf::FIELD_NAME => $this->app->get(\Prime\Security\Csrf::class)->token(),
        ]);
    }

    private function kernel(): Kernel
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->get(Kernel::class);

        return $kernel;
    }

    private function createApp(): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        // A dedicated page-cache directory keeps this test independent from the other suites.
        $app->config()->set('cache.page.directory', $this->pageCacheDir);
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $pdo = new PDO('sqlite:' . $this->databaseFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, username VARCHAR(80) NULL UNIQUE, bio TEXT NULL, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) NOT NULL UNIQUE, value LONGTEXT NULL, autoload INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');

        $now = '2026-11-16 12:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Accès complet', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute(['camille@prime-cms.local', password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT), 'Camille Admin', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1)');

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $user = $users->find(1);
        if ($user !== null) {
            $auth->login($user);
        }

        return $app;
    }
}
