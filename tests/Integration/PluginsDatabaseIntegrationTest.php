<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
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
use Prime\View\View;

/**
 * Real MySQL 8.0 & MariaDB 10.6 integration tests for Phase 5 plugins:
 * `pc_plugins` & `pc_migrations.source` migrations, real `hello-prime` activation/deactivation/uninstall,
 * HTTP rendering of `GET /hello-prime`, and transactional migration rollback on failed activation.
 */
#[Group('database')]
final class PluginsDatabaseIntegrationTest extends DatabaseTestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/prime-plugins-mysql-it-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);
        mkdir($this->tmpRoot . '/storage', 0755, true);
        mkdir($this->tmpRoot . '/custom-plugins', 0755, true);
    }

    protected function tearDown(): void
    {
        AssetPublisher::deleteDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function testHelloPrimeFullLifecycleAndPublicRouteOnRealMysqlAndMariaDb(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator(
            $this->connection,
            $schema,
            [dirname(__DIR__, 2) . '/database/migrations'],
        );

        $ran = $migrator->run();
        $this->assertCount(18, $ran);
        $this->assertTrue($schema->hasTable('plugins'));
        $this->assertTrue($schema->hasColumn('migrations', 'source'));

        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.url', 'https://prime-db.example');
        $app->config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app->boot();
        $app->container()->instance(Connection::class, $this->connection);
        $hooks = new Hooks();
        $router = new Router();
        $views = new View(dirname(__DIR__, 2) . '/resources/views');
        $settings = new Settings($this->connection);
        $app->container()->instance(Settings::class, $settings);
        $app->container()->instance(View::class, $views);
        $notices = new Notices($settings);
        $repository = new PluginRepository($this->connection, $schema);
        $autoloader = new Autoloader();
        $autoloader->register();

        $manager = new PluginManager(
            app: $app,
            pluginsPath: dirname(__DIR__, 2) . '/content/plugins',
            autoloader: $autoloader,
            repository: $repository,
            validator: new ManifestValidator(),
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets', '/assets/plugins'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/storage/loading.json'),
            migrator: $migrator,
            hooks: $hooks,
            router: $router,
            views: $views,
            adminMenu: new AdminMenu(),
            capabilities: new Capabilities($hooks),
            settings: $settings,
            db: $this->connection,
            notices: $notices,
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        // 1. Activate real `hello-prime` plugin against real MySQL/MariaDB
        $active = $manager->activate('hello-prime');
        $this->assertTrue($active->isActive());
        $this->assertTrue($schema->hasTable('hello_prime_greetings'));

        // Verify migration row tagged with source = 'hello-prime'
        $migRow = $this->connection->table('migrations')
            ->where('source', '=', 'hello-prime')
            ->first();
        $this->assertNotNull($migRow);
        $this->assertStringContainsString('create_hello_prime_greetings_table', (string) $migRow['migration']);

        // Verify plugin row in `pc_plugins`
        $pluginRow = $repository->find('hello-prime');
        $this->assertNotNull($pluginRow);
        $this->assertSame(PluginInfo::STATUS_ACTIVE, $pluginRow['status']);
        $this->assertSame('1.1.0', $pluginRow['version']);

        // Insert a greeting & customize setting, then dispatch GET /hello-prime
        $settings->set('plugin.hello-prime.greeting', 'Bienvenue sur MySQL Prime !');
        $this->connection->table('hello_prime_greetings')->insert([
            'author' => 'CI Bot',
            'message' => 'Test intégration Phase 5',
            'created_at' => '2026-11-15 12:00:00',
        ]);

        $request = Request::create('GET', '/hello-prime');
        $matched = $router->dispatch($request);
        $handler = $matched['route']->handler();
        $this->assertIsArray($handler);
        $controller = $app->make($handler[0]);
        $method = $handler[1];
        /** @var \Prime\Http\Response $response */
        $response = $controller->{$method}($request);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Bienvenue sur MySQL Prime !', $response->body());
        $this->assertStringContainsString('Test intégration Phase 5', $response->body());

        // 2. Deactivate preserves table & rows
        $manager->deactivate('hello-prime');
        $this->assertTrue($schema->hasTable('hello_prime_greetings'));
        $this->assertSame(1, $this->connection->table('hello_prime_greetings')->count());

        // 3. Uninstall (`deleteFiles: false` so repo file stays intact!) drops table, removes migration row, settings, assets & pc_plugins row
        $manager->uninstall('hello-prime', deleteFiles: false);
        $this->assertFalse($schema->hasTable('hello_prime_greetings'));
        $this->assertNull($repository->find('hello-prime'));
        $this->assertFalse($settings->has('plugin.hello-prime.greeting'));
        $this->assertSame(0, $this->connection->table('migrations')->where('source', '=', 'hello-prime')->count());
        $this->assertFalse(is_dir($this->tmpRoot . '/public-assets/hello-prime'));
    }

    public function testFailedActivationRollsBackPluginMigrationBatchAndMarksErrored(): void
    {
        $schema = new Schema($this->connection);
        $migrator = new Migrator(
            $this->connection,
            $schema,
            [dirname(__DIR__, 2) . '/database/migrations'],
        );
        $migrator->run();

        // Create a plugin with a valid migration whose activate() throws an exception
        $plugDir = $this->tmpRoot . '/custom-plugins/rollback-demo';
        mkdir($plugDir . '/src', 0755, true);
        mkdir($plugDir . '/migrations', 0755, true);

        file_put_contents($plugDir . '/plugin.json', json_encode([
            'slug' => 'rollback-demo',
            'name' => 'Rollback Demo',
            'description' => 'Throws during activate() after running migration.',
            'version' => '1.0.0',
            'author' => ['name' => 'Prime Tests'],
            'namespace' => 'RollbackDemo\\',
            'main' => 'RollbackDemo\\Plugin',
        ], JSON_THROW_ON_ERROR));

        file_put_contents($plugDir . '/migrations/2026_11_15_000001_create_rollback_demo_table.php', <<<'PHP'
<?php
declare(strict_types=1);
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('rollback_demo_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('rollback_demo_items');
    }
};
PHP);

        file_put_contents($plugDir . '/src/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace RollbackDemo;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use RuntimeException;

final class Plugin extends AbstractPlugin
{
    public function activate(PluginContext $context): void
    {
        throw new RuntimeException('Activation crashed on purpose');
    }
}
PHP);

        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $hooks = new Hooks();
        $settings = new Settings($this->connection);
        $repository = new PluginRepository($this->connection, $schema);
        $autoloader = new Autoloader();
        $autoloader->register();

        $manager = new PluginManager(
            app: $app,
            pluginsPath: $this->tmpRoot . '/custom-plugins',
            autoloader: $autoloader,
            repository: $repository,
            validator: new ManifestValidator(),
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/storage/loading.json'),
            migrator: $migrator,
            hooks: $hooks,
            router: new Router(),
            views: new View($this->tmpRoot),
            adminMenu: new AdminMenu(),
            capabilities: new Capabilities($hooks),
            settings: $settings,
            db: $this->connection,
            notices: new Notices($settings),
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        try {
            $manager->activate('rollback-demo');
            $this->fail('Expected activate() to throw PluginException');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Activation crashed on purpose', $e->getMessage());
        }

        // Migration table MUST have been rolled back, migration log removed, and plugin marked errored
        $this->assertFalse($schema->hasTable('rollback_demo_items'));
        $this->assertSame(0, $this->connection->table('migrations')->where('source', '=', 'rollback-demo')->count());

        $row = $repository->find('rollback-demo');
        $this->assertNotNull($row);
        $this->assertSame(PluginInfo::STATUS_ERRORED, $row['status']);
        $this->assertStringContainsString('Activation crashed on purpose', (string) $row['last_error']);
    }
}
