<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Console\CommandInterface;
use Prime\Console\Commands\MakePluginCommand;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Settings\Settings;
use Prime\Tests\Support\ApiTestFixture;
use Prime\View\View;

/**
 * `make:plugin` copies prime-starter under a new identity; the copy works on its own:
 * activation, admin, REST API, then an uninstall that removes its table and its folder.
 */
final class MakePluginFromStarterTest extends TestCase
{
    use ApiTestFixture;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/prime_make_plugin_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/plugins', 0755, true);
        mkdir($this->root . '/assets', 0755, true);
    }

    protected function tearDown(): void
    {
        AssetPublisher::deleteDirectory($this->root);
    }

    public function testTheGeneratedPluginIsRenamedEverywhere(): void
    {
        $dir = $this->make('acme-notes', '--name=Acme Notes');

        $manifest = json_decode((string) file_get_contents($dir . '/plugin.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame(['acme-notes', 'Acme Notes', 'AcmeNotes\\', 'AcmeNotes\\Plugin', '0.1.0', '/admin/acme-notes'], [
            $manifest['slug'] ?? null, $manifest['name'] ?? null, $manifest['namespace'] ?? null, $manifest['main'] ?? null, $manifest['version'] ?? null, $manifest['settings_url'] ?? null,
        ]);
        $this->assertFileExists($dir . '/tests/AcmeNotesTest.php');
        $this->assertFileExists($dir . '/migrations/2026_11_21_000001_create_acme_notes_notes_table.php');

        $leftovers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match('/prime[-_ ]?starter/i', (string) file_get_contents($file->getPathname())) === 1) {
                $leftovers[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        $this->assertSame([], $leftovers, 'traces de prime-starter dans la copie');

        $this->assertSame(CommandInterface::FAILURE, (new MakePluginCommand($this->createApp(null), $this->root . '/plugins'))->execute(['acme-notes'], new ConsoleOutput(buffered: true)));
    }

    public function testTheGeneratedPluginWorksAndUninstallsCleanly(): void
    {
        $this->make('acme-notes', '--name=Acme Notes');
        $app = $this->appWithPlugins('admin');
        $manager = $app->get(PluginManager::class);
        $manager->activate('acme-notes');

        $admin = $app->get(Kernel::class)->handle(Request::create('GET', '/admin/acme-notes', headers: ['Accept' => 'text/html']));
        $this->assertSame(200, $admin->status());
        $this->assertStringContainsString('Aucune note pour l’instant', $admin->body());

        $token = $this->tokens($app)->issue($this->user($app, 1), 'make', ['*'])['token'];
        $created = $this->api($app, 'POST', '/api/acme-notes/v1/notes', ['title' => 'Copie', 'public' => true], $token);
        $this->assertSame(201, $created->status());
        $this->assertSame(['Copie'], array_column($this->json($this->api($app, 'GET', '/api/acme-notes/v1/notes'))['data'] ?? [], 'title'));
        $this->assertSame(403, $this->api($app, 'POST', '/api/acme-notes/v1/notes', ['title' => 'Non'], $this->tokens($app)->issue($this->user($app, 2), 'make', ['*'])['token'])->status());

        $manager->deactivate('acme-notes');
        $manager->uninstall('acme-notes');

        $tables = $this->db($app)->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'pc_acme_notes_notes'");
        $this->assertNotFalse($tables);
        $this->assertSame([], $tables->fetchAll());
        $this->assertDirectoryDoesNotExist($this->root . '/plugins/acme-notes');
    }

    public function testMinimalStillGeneratesTheBareSkeleton(): void
    {
        $dir = $this->make('bare-addon', '--minimal');

        $this->assertFileExists($dir . '/src/Plugin.php');
        $this->assertDirectoryDoesNotExist($dir . '/tests');
    }

    private function make(string $slug, string ...$options): string
    {
        $output = new ConsoleOutput(buffered: true);
        $command = new MakePluginCommand($this->createApp(null), $this->root . '/plugins', dirname(__DIR__, 2) . '/content/plugins/prime-starter');
        $this->assertSame(CommandInterface::SUCCESS, $command->execute([$slug, ...$options], $output), $output->stderr());

        return $this->root . '/plugins/' . $slug;
    }

    private function appWithPlugins(string $as): Application
    {
        $app = $this->createApp($as);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');

        $root = $this->root;
        $app->container()->instance(AssetPublisher::class, new AssetPublisher($root . '/assets', '/assets/plugins'));
        $app->container()->singleton(PluginManager::class, static function () use ($app, $root): PluginManager {
            $autoloader = new Autoloader();
            $autoloader->register();

            return new PluginManager(
                app: $app,
                pluginsPath: $root . '/plugins',
                autoloader: $autoloader,
                repository: $app->get(PluginRepository::class),
                validator: $app->get(ManifestValidator::class),
                resolver: $app->get(DependencyResolver::class),
                assetPublisher: $app->get(AssetPublisher::class),
                fatalGuard: $app->get(FatalLoadGuard::class),
                migrator: $app->get(Migrator::class),
                hooks: $app->get(Hooks::class),
                router: $app->get(Router::class),
                views: $app->get(View::class),
                adminMenu: $app->get(AdminMenu::class),
                capabilities: $app->get(Capabilities::class),
                settings: $app->get(Settings::class),
                db: $app->get(Connection::class),
                notices: $app->get(Notices::class),
                logger: $app->get(Logger::class),
            );
        });

        return $app;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function api(Application $app, string $method, string $uri, ?array $json = null, ?string $token = null): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, headers: $headers, rawBody: $json !== null ? (string) json_encode($json) : ''));
    }
}
