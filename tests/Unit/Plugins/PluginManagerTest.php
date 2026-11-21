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

        // Simulate a leftover fatal crash witness for another plugin: a request that wrote its
        // witness then died (the temporary guard is destroyed without clear(), lock released).
        (new FatalLoadGuard($this->tmpRoot . '/storage/loading.json'))->markLoading('crashed-prev', 'boot');

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

        // C2: last_error uses short exception class and NEVER exposes absolute server path
        $bootRow = $this->repository->find('boot-throw');
        $this->assertNotNull($bootRow);
        $bootError = (string) ($bootRow['last_error'] ?? '');
        $this->assertStringStartsWith('RuntimeException:', $bootError);
        $this->assertStringNotContainsString($this->tmpRoot, $bootError);
        $this->assertStringNotContainsString(dirname(__DIR__, 3), $bootError);

        // C2: full absolute path + stack trace ARE written in plugin.boot-throw daily log
        $bootLogs = glob($this->tmpRoot . '/logs/plugin.boot-throw-*.log');
        $this->assertIsArray($bootLogs);
        $this->assertNotEmpty($bootLogs);
        $bootLogContent = (string) file_get_contents($bootLogs[0]);
        $this->assertStringContainsString('Plugin.php', $bootLogContent);
        $this->assertStringContainsString('trace', $bootLogContent);

        // C3: 4 errored plugins produce strictly ONE consolidated summary notice pointing to /admin/plugins?status=errored
        $allNotices = $this->notices->all();
        $this->assertCount(1, $allNotices);
        $this->assertSame(PluginManager::ERRORED_SUMMARY_NOTICE_ID, $allNotices[0]->id);
        $this->assertSame('4 plugins ont été désactivés suite à une erreur', $allNotices[0]->title);
        $this->assertSame('Voir les plugins en erreur', $allNotices[0]->actionLabel);
        $this->assertSame('/admin/plugins?status=errored', $allNotices[0]->actionUrl);
    }

    public function testSingleErroredPluginShowsNamedNoticeRelativePathTruncationAndHidesExternalPath(): void
    {
        // 1. Unique plugin throwing a >300-char message from inside its own src/Plugin.php
        $suffix = bin2hex(random_bytes(4));
        $ns = 'PathLeak' . ucfirst($suffix);
        $inDir = $this->tmpRoot . '/plugins/path-leak';
        mkdir($inDir . '/src', 0755, true);
        file_put_contents($inDir . '/plugin.json', (string) json_encode([
            'slug' => 'path-leak',
            'name' => 'Path Leak Check',
            'description' => 'Verifies plugin-relative file path and 300-char truncation.',
            'version' => '1.0.0',
            'author' => ['name' => 'Prime QA'],
            'license' => 'proprietary',
            'namespace' => $ns . '\\',
            'main' => $ns . '\\Plugin',
            'requires' => ['prime' => '>=0.5.0', 'php' => '^8.2'],
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents($inDir . '/src/Plugin.php', <<<PHP
<?php
declare(strict_types=1);
namespace {$ns};
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use RuntimeException;
final class Plugin extends AbstractPlugin
{
    public function boot(PluginContext \$context): void
    {
        throw new RuntimeException('Erreur très longue : ' . str_repeat('X', 350));
    }
}
PHP);

        $this->repository->markActive('path-leak', '1.0.0', 'pl');
        $manager = $this->makeManager();
        $manager->bootActivePlugins();

        $inRow = $this->repository->find('path-leak');
        $this->assertNotNull($inRow);
        $inError = (string) ($inRow['last_error'] ?? '');
        $this->assertStringStartsWith('RuntimeException:', $inError);
        $this->assertStringContainsString('(src/Plugin.php:11)', $inError);
        $this->assertStringContainsString('…', $inError);
        $this->assertStringNotContainsString($this->tmpRoot, $inError);
        $this->assertStringNotContainsString(dirname(__DIR__, 3), $inError);

        // Full server path + trace are logged exclusively in plugin.path-leak daily log
        $logs = glob($this->tmpRoot . '/logs/plugin.path-leak-*.log');
        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs);
        $logContent = (string) file_get_contents($logs[0]);
        $this->assertStringContainsString($this->tmpRoot, $logContent);
        $this->assertStringContainsString('trace', $logContent);

        // Single errored plugin gets a named notice
        $singleNotices = $this->notices->all();
        $this->assertCount(1, $singleNotices);
        $this->assertSame('Le plugin Path Leak Check a été désactivé suite à une erreur', $singleNotices[0]->title);
        $this->assertSame('/admin/plugins?status=errored', $singleNotices[0]->actionUrl);

        // Uninstall path-leak -> notice disappears
        $manager->uninstall('path-leak');
        $this->assertSame([], $this->notices->all());

        // 2. Plugin whose exception originates outside the plugin folder -> NO file path in last_error
        $pluginDir = $this->tmpRoot . '/plugins/external-err';
        mkdir($pluginDir . '/src', 0755, true);
        file_put_contents($pluginDir . '/plugin.json', (string) json_encode([
            'slug' => 'external-err',
            'name' => 'External Error Plugin',
            'description' => 'Throws an exception whose class file is outside the plugin folder.',
            'version' => '1.0.0',
            'author' => ['name' => 'Prime QA'],
            'license' => 'proprietary',
            'namespace' => 'ExternalErr\\',
            'main' => 'ExternalErr\\Plugin',
            'requires' => ['prime' => '>=0.5.0', 'php' => '^8.2'],
        ], JSON_UNESCAPED_SLASHES));

        $this->repository->markActive('external-err', '1.0.0', 'ext');

        $manager2 = $this->makeManager();
        $manager2->bootActivePlugins();

        $row = $this->repository->find('external-err');
        $this->assertNotNull($row);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $row['status']);
        $lastError = (string) ($row['last_error'] ?? '');
        $this->assertStringStartsWith('PluginException:', $lastError);
        $this->assertStringNotContainsString('PluginManager.php', $lastError);
        $this->assertStringNotContainsString(dirname(__DIR__, 3), $lastError);

        $allNotices = $this->notices->all();
        $this->assertCount(1, $allNotices);
        $this->assertSame('Le plugin External Error Plugin a été désactivé suite à une erreur', $allNotices[0]->title);
        $this->assertSame('/admin/plugins?status=errored', $allNotices[0]->actionUrl);
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
        $app = new Application(dirname(__DIR__, 3), loadEnv: false, registerErrorHandler: false);
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
