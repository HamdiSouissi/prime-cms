<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use FixtureBaseLib\Plugin as BaseLibPlugin;
use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginInfo;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;

final class PluginManagerTest extends TestCase
{
    private string $tmpRoot;
    private Connection $connection;
    private Schema $schema;
    private Hooks $hooks;
    private Settings $settings;
    private Notices $notices;
    private PluginRepository $repository;
    private FatalLoadGuard $fatalGuard;
    private AssetPublisher $assetPublisher;
    private Router $router;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/prime-pm-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/plugins', 0755, true);
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);
        mkdir($this->tmpRoot . '/storage', 0755, true);

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');

        $this->connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $this->schema = new Schema($this->connection);
        $this->hooks = new Hooks();
        $this->settings = new Settings($this->connection);
        $this->notices = new Notices($this->settings);
        $this->repository = new PluginRepository($this->connection, $this->schema);
        $this->fatalGuard = new FatalLoadGuard($this->tmpRoot . '/storage/loading.json');
        $this->assetPublisher = new AssetPublisher($this->tmpRoot . '/public-assets', '/assets/plugins');
        $this->router = new Router();
    }

    protected function tearDown(): void
    {
        AssetPublisher::deleteDirectory($this->tmpRoot);
    }

    public function testHelloPrimeFullLifecycleActivateDeactivateAndUninstall(): void
    {
        $this->copyDir(dirname(__DIR__, 3) . '/content/plugins/hello-prime', $this->tmpRoot . '/plugins/hello-prime');

        $manager = $this->makeManager();
        $discovered = $manager->discover();
        $this->assertArrayHasKey('hello-prime', $discovered);
        $this->assertTrue($discovered['hello-prime']->isInactive());

        $events = [];
        $this->hooks->on('plugin.activated', static function () use (&$events): void {
            $events[] = 'activated';
        });
        $this->hooks->on('plugin.deactivated', static function () use (&$events): void {
            $events[] = 'deactivated';
        });
        $this->hooks->on('plugin.uninstalled', static function () use (&$events): void {
            $events[] = 'uninstalled';
        });

        // Activate -> table created, assets published, settings initialized, route registered, notice added
        $activeInfo = $manager->activate('hello-prime');
        $this->assertTrue($activeInfo->isActive());
        $this->assertTrue($this->schema->hasTable('hello_prime_greetings'));
        $this->assertTrue(is_file($this->tmpRoot . '/public-assets/hello-prime/css/hello-prime.css'));
        $this->assertSame('Bonjour depuis Hello Prime !', $this->settings->get('plugin.hello-prime.greeting'));
        $this->assertCount(1, $this->notices->all());

        // Uninstall while active must be rejected
        try {
            $manager->uninstall('hello-prime');
            $this->fail('Expected uninstall of active plugin to throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('actif', $e->getMessage());
        }

        // Deactivate -> keeps table and settings
        $inactiveInfo = $manager->deactivate('hello-prime');
        $this->assertTrue($inactiveInfo->isInactive());
        $this->assertTrue($this->schema->hasTable('hello_prime_greetings'));
        $this->assertSame('Bonjour depuis Hello Prime !', $this->settings->get('plugin.hello-prime.greeting'));

        // Uninstall with deleteFiles = true -> drops table, purges settings/notices/assets/DB row/dir
        $manager->uninstall('hello-prime', deleteFiles: true);
        $this->assertFalse($this->schema->hasTable('hello_prime_greetings'));
        $this->assertFalse($this->settings->has('plugin.hello-prime.greeting'));
        $this->assertFalse(is_dir($this->tmpRoot . '/public-assets/hello-prime'));
        $this->assertFalse(is_dir($this->tmpRoot . '/plugins/hello-prime'));
        $this->assertNull($this->repository->find('hello-prime'));
        $this->assertSame(['activated', 'deactivated', 'uninstalled'], $events);
    }

    public function testFaultIsolationForBootThrowSyntaxErrorListenerThrowAndFatalWitness(): void
    {
        $fixtures = dirname(__DIR__, 2) . '/Fixtures/plugins';
        $this->copyDir($fixtures . '/boot-throw', $this->tmpRoot . '/plugins/boot-throw');
        $this->copyDir($fixtures . '/syntax-error', $this->tmpRoot . '/plugins/syntax-error');
        $this->copyDir($fixtures . '/listener-throw', $this->tmpRoot . '/plugins/listener-throw');

        // Mark all three active in DB to simulate boot-time failure recovery
        $this->repository->markActive('boot-throw', '1.0.0', 'h1');
        $this->repository->markActive('syntax-error', '1.0.0', 'h2');
        $this->repository->markActive('listener-throw', '1.0.0', 'h3');

        // Simulate a leftover fatal crash witness for another plugin
        $this->fatalGuard->markLoading('crashed-prev', 'boot');

        $failedSlugs = [];
        $this->hooks->on('plugin.failed', static function (string $slug) use (&$failedSlugs): void {
            $failedSlugs[] = $slug;
        });

        $manager = $this->makeManager();
        $manager->bootActivePlugins();

        // boot-throw, syntax-error, crashed-prev must be marked errored; listener-throw boots cleanly
        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('boot-throw')['status'] ?? null);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('syntax-error')['status'] ?? null);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('crashed-prev')['status'] ?? null);
        $this->assertSame(PluginInfo::STATUS_ACTIVE, $this->repository->find('listener-throw')['status'] ?? null);
        $this->assertNull($this->fatalGuard->readWitness());

        // Triggering listener-throw hook must isolate its exception while core continues!
        $coreRan = false;
        $this->hooks->on('fixture.trigger', static function () use (&$coreRan): void {
            $coreRan = true;
        }, priority: 20);
        $this->hooks->emit('fixture.trigger');

        $this->assertTrue($coreRan);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('listener-throw')['status'] ?? null);
        $this->assertContains('boot-throw', $failedSlugs);
        $this->assertContains('syntax-error', $failedSlugs);
        $this->assertContains('listener-throw', $failedSlugs);
        $this->assertGreaterThanOrEqual(3, count($this->notices->all()));
    }

    public function testDependenciesTopologicalOrderCycleDetectionImpossiblePrimeAndSafeMode(): void
    {
        $fixtures = dirname(__DIR__, 2) . '/Fixtures/plugins';
        $this->copyDir($fixtures . '/base-lib', $this->tmpRoot . '/plugins/base-lib');
        $this->copyDir($fixtures . '/dep-consumer', $this->tmpRoot . '/plugins/dep-consumer');
        $this->copyDir($fixtures . '/impossible-prime', $this->tmpRoot . '/plugins/impossible-prime');
        $this->copyDir($fixtures . '/cycle-alpha', $this->tmpRoot . '/plugins/cycle-alpha');
        $this->copyDir($fixtures . '/cycle-beta', $this->tmpRoot . '/plugins/cycle-beta');

        $manager = $this->makeManager();

        // Impossible Prime version cannot activate
        try {
            $manager->activate('impossible-prime');
            $this->fail('Expected impossible-prime activation to fail');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Prime CMS', $e->getMessage());
        }

        // Dependent cannot activate while base-lib is inactive
        try {
            $manager->activate('dep-consumer');
            $this->fail('Expected dep-consumer activation to fail before base-lib');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Base Library', $e->getMessage());
        }

        // Activate base-lib then dep-consumer
        $manager->activate('base-lib');
        $manager->activate('dep-consumer');

        // Cannot deactivate base-lib while dep-consumer is active
        try {
            $manager->deactivate('base-lib');
            $this->fail('Expected base-lib deactivation to be blocked');
        } catch (PluginException $e) {
            $this->assertStringContainsString('dep-consumer', $e->getMessage());
        }

        // Mark cycle pair active directly in DB and boot a fresh PluginManager to test topological order & cycle isolation
        $this->repository->markActive('cycle-alpha', '1.0.0', 'ca');
        $this->repository->markActive('cycle-beta', '1.0.0', 'cb');

        BaseLibPlugin::$events = [];
        $freshManager = $this->makeManager();
        $freshManager->bootActivePlugins();

        $this->assertSame([
            'base-lib:register',
            'dep-consumer:register',
            'base-lib:boot',
            'dep-consumer:boot',
        ], BaseLibPlugin::$events);

        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('cycle-alpha')['status'] ?? null);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $this->repository->find('cycle-beta')['status'] ?? null);

        // Safe mode must skip ALL active plugins on boot
        BaseLibPlugin::$events = [];
        $safeManager = $this->makeManager(safeMode: true);
        $safeManager->bootActivePlugins();
        $this->assertSame([], BaseLibPlugin::$events);
        $this->assertSame([], $safeManager->loaded());
    }

    private function makeManager(bool $safeMode = false): PluginManager
    {
        $app = new Application(dirname(__DIR__, 3), registerErrorHandler: false);
        $autoloader = new Autoloader();
        $autoloader->register();

        return new PluginManager(
            app: $app,
            pluginsPath: $this->tmpRoot . '/plugins',
            autoloader: $autoloader,
            repository: $this->repository,
            validator: new ManifestValidator(),
            resolver: new DependencyResolver(),
            assetPublisher: $this->assetPublisher,
            fatalGuard: $this->fatalGuard,
            migrator: new Migrator($this->connection, $this->schema, [], $this->hooks),
            hooks: $this->hooks,
            router: $this->router,
            views: new View($this->tmpRoot),
            adminMenu: new AdminMenu(),
            capabilities: new Capabilities($this->hooks),
            settings: $this->settings,
            db: $this->connection,
            notices: $this->notices,
            logger: new Logger($this->tmpRoot . '/logs'),
            safeMode: $safeMode,
        );
    }

    private function copyDir(string $source, string $dest): void
    {
        mkdir($dest, 0755, true);
        $items = scandir($source);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $source . '/' . $item;
            $dst = $dest . '/' . $item;
            if (is_dir($src)) {
                $this->copyDir($src, $dst);
            } else {
                copy($src, $dst);
            }
        }
    }
}
