<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

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
use Prime\Plugins\Exception\PluginInstallationException;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginInfo;
use Prime\Plugins\PluginInstaller;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;
use ZipArchive;

final class PluginInstallerTest extends TestCase
{
    private string $tmpRoot;
    private PluginInstaller $installer;
    private PluginRepository $repository;
    private Hooks $hooks;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not installed in this environment.');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/prime-zip-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/plugins', 0755, true);
        mkdir($this->tmpRoot . '/tmp', 0755, true);
        mkdir($this->tmpRoot . '/zips', 0755, true);
        mkdir($this->tmpRoot . '/public-assets', 0755, true);
        mkdir($this->tmpRoot . '/logs', 0755, true);

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $schema = new Schema($connection);
        $this->hooks = new Hooks();
        $settings = new Settings($connection);
        $this->repository = new PluginRepository($connection, $schema);

        $validator = new ManifestValidator();
        $manager = new PluginManager(
            app: new Application(dirname(__DIR__, 3), registerErrorHandler: false),
            pluginsPath: $this->tmpRoot . '/plugins',
            autoloader: new Autoloader(),
            repository: $this->repository,
            validator: $validator,
            resolver: new DependencyResolver(),
            assetPublisher: new AssetPublisher($this->tmpRoot . '/public-assets'),
            fatalGuard: new FatalLoadGuard($this->tmpRoot . '/tmp/loading.json'),
            migrator: new Migrator($connection, $schema, [], $this->hooks),
            hooks: $this->hooks,
            router: new Router(),
            views: new View($this->tmpRoot),
            adminMenu: new AdminMenu(),
            capabilities: new Capabilities($this->hooks),
            settings: $settings,
            db: $connection,
            notices: new Notices($settings),
            logger: new Logger($this->tmpRoot . '/logs'),
        );

        $this->installer = new PluginInstaller(
            pluginsPath: $this->tmpRoot . '/plugins',
            tmpPath: $this->tmpRoot . '/tmp',
            validator: $validator,
            repository: $this->repository,
            manager: $manager,
            hooks: $this->hooks,
            maxUploadMb: 5,
            maxZipFiles: 50,
            maxUncompressedBytes: 2 * 1024 * 1024,
            maxCompressionRatio: 50.0,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpRoot)) {
            AssetPublisher::deleteDirectory($this->tmpRoot);
        }
    }

    public function testInstallsValidZipInactiveHandlesConflictUpgradeAndForceDowngrade(): void
    {
        $installedEvents = 0;
        $this->hooks->on('plugin.installed', static function () use (&$installedEvents): void {
            $installedEvents++;
        });

        $zipV1 = $this->createValidPluginZip('zip-demo', '1.0.0');
        $res1 = $this->installer->installFromZip($zipV1);

        $this->assertFalse($res1['replaced']);
        $this->assertSame('1.0.0', $res1['manifest']->version);
        $this->assertTrue(is_file($this->tmpRoot . '/plugins/zip-demo/plugin.json'));
        $this->assertSame(PluginInfo::STATUS_INACTIVE, $this->repository->find('zip-demo')['status'] ?? null);
        $this->assertSame(1, $installedEvents);

        // Re-uploading v1.1.0 without replaceExisting must throw conflict exception
        $zipV2 = $this->createValidPluginZip('zip-demo', '1.1.0');
        try {
            $this->installer->installFromZip($zipV2, replaceExisting: false);
            $this->fail('Expected replacement conflict exception');
        } catch (PluginInstallationException $e) {
            $this->assertTrue($e->isReplacementConflict());
            $this->assertSame('zip-demo', $e->conflictSlug);
            $this->assertSame('1.0.0', $e->existingVersion);
            $this->assertSame('1.1.0', $e->incomingVersion);
            $this->assertFalse($e->isDowngradeOrSame);
        }

        // Confirm upgrade with replaceExisting: true -> succeeds atomically
        $res2 = $this->installer->installFromZip($zipV2, replaceExisting: true);
        $this->assertTrue($res2['replaced']);
        $this->assertSame('1.0.0', $res2['previous_version']);
        $this->assertSame('1.1.0', $res2['manifest']->version);

        // Downgrade v1.0.0 with replaceExisting: true but force: false -> rejected
        try {
            $this->installer->installFromZip($zipV1, replaceExisting: true, force: false);
            $this->fail('Expected downgrade rejection without --force');
        } catch (PluginInstallationException $e) {
            $this->assertTrue($e->isDowngradeOrSame);
        }

        // Force downgrade with force: true -> succeeds
        $res3 = $this->installer->installFromZip($zipV1, replaceExisting: true, force: true);
        $this->assertTrue($res3['replaced']);
        $this->assertSame('1.0.0', $res3['manifest']->version);
    }

    public function testRejectsZipSlipAbsolutePathBackslashSymlinkForbiddenFilesAndZipBomb(): void
    {
        // 1. Not a ZIP file
        $notZip = $this->tmpRoot . '/zips/fake.zip';
        file_put_contents($notZip, '<?php echo "not a zip";');
        $this->assertRejected($notZip, 'signature');

        // 2. Zip Slip path traversal (`../evil.php`)
        $zipSlip = $this->createCustomZip('zip-slip.zip', [
            'demo/plugin.json' => $this->validManifestJson('demo', '1.0.0'),
            'demo/../../evil.php' => '<?php evil();',
        ]);
        $this->assertRejected($zipSlip, 'traversée');

        // 3. Absolute path (`/etc/passwd`)
        $absZip = $this->createCustomZip('abs.zip', [
            '/etc/passwd' => 'root:x:0:0:',
        ]);
        $this->assertRejected($absZip, 'absolu');

        // 4. Backslash path
        $backslashZip = $this->createCustomZip('backslash.zip', [
            'demo\\evil.php' => '<?php',
        ]);
        $this->assertRejected($backslashZip, 'antislash');

        // 5. Forbidden files (.phar, .phtml, .htaccess, .user.ini)
        foreach (['evil.phar', 'shell.phtml', '.htaccess', '.user.ini'] as $badFile) {
            $badZip = $this->createCustomZip('bad-' . md5($badFile) . '.zip', [
                'demo/plugin.json' => $this->validManifestJson('demo', '1.0.0'),
                'demo/' . $badFile => 'payload',
            ]);
            $this->assertRejected($badZip, 'interdit');
        }

        // 6. Multiple root folders
        $multiRoot = $this->createCustomZip('multi-root.zip', [
            'demo/plugin.json' => $this->validManifestJson('demo', '1.0.0'),
            'other/readme.txt' => 'extra',
        ]);
        $this->assertRejected($multiRoot, 'unique dossier racine');

        // 7. Root folder name mismatch vs plugin.json slug
        $mismatch = $this->createCustomZip('mismatch.zip', [
            'wrong-dir/plugin.json' => $this->validManifestJson('demo', '1.0.0'),
        ]);
        $this->assertRejected($mismatch, 'ne correspond pas');

        // 8. Symlink inside ZIP (Unix mode S_IFLNK = 0120777)
        $symlinkZip = $this->tmpRoot . '/zips/symlink.zip';
        $zip = new ZipArchive();
        $zip->open($symlinkZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('demo/plugin.json', $this->validManifestJson('demo', '1.0.0'));
        $zip->addFromString('demo/link', '/etc/passwd');
        $zip->setExternalAttributesName('demo/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->assertRejected($symlinkZip, 'liens symboliques');

        // 9. ZIP bomb (high compression ratio / oversized uncompressed payload)
        $bombZip = $this->createCustomZip('bomb.zip', [
            'demo/plugin.json' => $this->validManifestJson('demo', '1.0.0'),
            'demo/huge.txt' => str_repeat('A', 3 * 1024 * 1024),
        ]);
        $this->assertRejected($bombZip, 'taille décompressée');
    }

    private function assertRejected(string $zipPath, string $expectedSubstring): void
    {
        try {
            $this->installer->installFromZip($zipPath);
            $this->fail(sprintf('Expected ZIP [%s] to be rejected with "%s".', basename($zipPath), $expectedSubstring));
        } catch (PluginInstallationException $exception) {
            $this->assertStringContainsString(
                mb_strtolower($expectedSubstring),
                mb_strtolower($exception->getMessage()),
                sprintf('Unexpected error for [%s]: %s', basename($zipPath), $exception->getMessage()),
            );
        }
    }

    private function createValidPluginZip(string $slug, string $version): string
    {
        return $this->createCustomZip($slug . '-' . $version . '.zip', [
            $slug . '/plugin.json' => $this->validManifestJson($slug, $version),
            $slug . '/src/Plugin.php' => "<?php\ndeclare(strict_types=1);\nnamespace ZipDemo;\nuse Prime\\Plugins\\AbstractPlugin;\nfinal class Plugin extends AbstractPlugin {}\n",
        ]);
    }

    /**
     * @param array<string, string> $entries
     */
    private function createCustomZip(string $filename, array $entries): string
    {
        $path = $this->tmpRoot . '/zips/' . $filename;
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);

        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }

        $zip->close();

        return $path;
    }

    private function validManifestJson(string $slug, string $version): string
    {
        return json_encode([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'description' => 'ZIP test plugin.',
            'version' => $version,
            'author' => ['name' => 'Prime Tests'],
            'namespace' => 'ZipDemo\\',
            'main' => 'ZipDemo\\Plugin',
            'requires' => ['prime' => '^0.5', 'php' => '^8.2'],
        ], JSON_THROW_ON_ERROR);
    }
}
