<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Console\CommandInterface;
use Prime\Console\Commands\MakePluginCommand;
use Prime\Console\Commands\PluginActivateCommand;
use Prime\Console\Commands\PluginDeactivateCommand;
use Prime\Console\Commands\PluginInstallCommand;
use Prime\Console\Commands\PluginListCommand;
use Prime\Console\Commands\PluginPublishAssetsCommand;
use Prime\Console\Commands\PluginUninstallCommand;
use Prime\Console\ConsoleOutput;
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
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginInstaller;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;
use ZipArchive;

final class PluginCommandsTest extends TestCase
{
    private string $tmpRoot;
    private Application $app;
    private PluginManager $manager;
    private PluginInstaller $installer;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/prime-cli-plugins-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/plugins', 0755, true);
        mkdir($this->tmpRoot . '/tmp', 0755, true);
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $schema = new Schema($connection);
        $hooks = new Hooks();
        $settings = new Settings($connection);
        $repository = new PluginRepository($connection, $schema);
        $validator = new ManifestValidator();
        $autoloader = new Autoloader();
        $autoloader->register();

        $this->app = new Application(dirname(__DIR__, 3), loadEnv: false, registerErrorHandler: false);

        $this->manager = new PluginManager(
            app: $this->app,
            pluginsPath: $this->tmpRoot . '/plugins',
            autoloader: $autoloader,
            repository: $repository,
            validator: $validator,
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/tmp/loading.json'),
            migrator: new Migrator($connection, $schema, [], $hooks),
            hooks: $hooks,
            router: new Router(),
            views: new View($this->tmpRoot),
            adminMenu: new AdminMenu(),
            capabilities: new Capabilities($hooks),
            settings: $settings,
            db: $connection,
            notices: new Notices($settings),
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        $this->installer = new PluginInstaller(
            pluginsPath: $this->tmpRoot . '/plugins',
            tmpPath: $this->tmpRoot . '/tmp',
            validator: $validator,
            repository: $repository,
            manager: $this->manager,
            hooks: $hooks,
        );
    }

    protected function tearDown(): void
    {
        AssetPublisher::deleteDirectory($this->tmpRoot);
    }

    public function testMakePluginAndFullCliLifecycle(): void
    {
        // 1. make:plugin scaffolds a valid activatable plugin with default proprietary license
        $outMake = new ConsoleOutput(buffered: true);
        $makeCmd = new MakePluginCommand($this->app, $this->tmpRoot . '/plugins');
        $this->assertSame(CommandInterface::SUCCESS, $makeCmd->execute(['sample-addon', '--name=Sample Addon', '--minimal'], $outMake));
        $manifestPath = $this->tmpRoot . '/plugins/sample-addon/plugin.json';
        $this->assertTrue(is_file($manifestPath));
        /** @var array<string, mixed> $decodedManifest */
        $decodedManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('proprietary', $decodedManifest['license']);

        // --license= overrides the default license
        $outMit = new ConsoleOutput(buffered: true);
        $this->assertSame(CommandInterface::SUCCESS, $makeCmd->execute(['mit-addon', '--license=MIT', '--minimal'], $outMit));
        /** @var array<string, mixed> $mitManifest */
        $mitManifest = json_decode((string) file_get_contents($this->tmpRoot . '/plugins/mit-addon/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('MIT', $mitManifest['license']);

        // Official hello-prime also defaults to proprietary license
        /** @var array<string, mixed> $helloManifest */
        $helloManifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/content/plugins/hello-prime/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('proprietary', $helloManifest['license']);

        file_put_contents($this->tmpRoot . '/plugins/sample-addon/assets/style.css', 'body{}');

        // 2. plugin:list --json shows sample-addon inactive
        $outList = new ConsoleOutput(buffered: true);
        $listCmd = new PluginListCommand($this->manager);
        $this->assertSame(CommandInterface::SUCCESS, $listCmd->execute(['--json'], $outList));
        $this->assertStringContainsString('"sample-addon"', $outList->stdout());

        // 3. plugin:activate activates it cleanly
        $outAct = new ConsoleOutput(buffered: true);
        $actCmd = new PluginActivateCommand($this->manager);
        $this->assertSame(CommandInterface::SUCCESS, $actCmd->execute(['sample-addon'], $outAct));
        $this->assertStringContainsString('activé', $outAct->stdout());

        // 4. plugin:publish-assets publishes static assets
        $outPub = new ConsoleOutput(buffered: true);
        $pubCmd = new PluginPublishAssetsCommand($this->manager);
        $this->assertSame(CommandInterface::SUCCESS, $pubCmd->execute(['sample-addon'], $outPub));
        $this->assertStringContainsString('1 fichier(s)', $outPub->stdout());

        // 5. plugin:deactivate deactivates it
        $outDeact = new ConsoleOutput(buffered: true);
        $deactCmd = new PluginDeactivateCommand($this->manager);
        $this->assertSame(CommandInterface::SUCCESS, $deactCmd->execute(['sample-addon'], $outDeact));
        $this->assertStringContainsString('désactivé', $outDeact->stdout());

        // 6. plugin:uninstall --keep-files cleans DB but keeps directory on disk
        $outUninst = new ConsoleOutput(buffered: true);
        $uninstCmd = new PluginUninstallCommand($this->manager);
        $this->assertSame(CommandInterface::SUCCESS, $uninstCmd->execute(['sample-addon', '--keep-files'], $outUninst));
        $this->assertTrue(is_dir($this->tmpRoot . '/plugins/sample-addon'));

        // 7. plugin:install from ZIP works end to end
        if (class_exists(ZipArchive::class)) {
            $zipPath = $this->tmpRoot . '/tmp/cli-zip.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('cli-zip/plugin.json', json_encode([
                'slug' => 'cli-zip',
                'name' => 'CLI Zip',
                'description' => 'Installed via CLI.',
                'version' => '1.0.0',
                'author' => ['name' => 'Prime'],
                'namespace' => 'CliZip\\',
                'main' => 'CliZip\\Plugin',
            ], JSON_THROW_ON_ERROR));
            $zip->addFromString('cli-zip/src/Plugin.php', "<?php\ndeclare(strict_types=1);\nnamespace CliZip;\nuse Prime\\Plugins\\AbstractPlugin;\nfinal class Plugin extends AbstractPlugin {}\n");
            $zip->close();

            $outInst = new ConsoleOutput(buffered: true);
            $instCmd = new PluginInstallCommand($this->installer);
            $this->assertSame(CommandInterface::SUCCESS, $instCmd->execute([$zipPath], $outInst));
            $this->assertStringContainsString('cli-zip', $outInst->stdout());
        }
    }
}
