<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Console;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Console\Commands\DbCheckCommand;
use Prime\Console\Commands\InstallCommand;
use Prime\Console\Commands\MakeMigrationCommand;
use Prime\Console\Commands\MigrateFreshCommand;
use Prime\Console\Commands\SettingsGetCommand;
use Prime\Console\Commands\SettingsSetCommand;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migration;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Install\Installer;
use Prime\Kernel\Application;
use Prime\Settings\Settings;

final class DatabaseCommandsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_cli_db_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                unlink($this->tempDir . '/' . $entry);
            }
            rmdir($this->tempDir);
        }
    }

    public function testConsoleListsAllPhase3DatabaseAndInstallCommands(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $app->boot();

        $console = new ConsoleApplication($app);
        $out = new ConsoleOutput(buffered: true);

        $this->assertSame(0, $console->run(['bin/prime', 'list'], $out));

        foreach ([
            'db:check',
            'install',
            'make:migration',
            'migrate',
            'migrate:fresh',
            'migrate:reset',
            'migrate:rollback',
            'migrate:status',
            'settings:get',
            'settings:set',
        ] as $cmd) {
            $this->assertStringContainsString($cmd, $out->stdout());
        }
    }

    public function testMakeMigrationCreatesTimestampedValidMigrationFile(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $cmd = new MakeMigrationCommand($app, $this->tempDir);

        $out = new ConsoleOutput(buffered: true);
        $code = $cmd->execute(['create_posts_table'], $out);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Migration créée :', $out->stdout());

        $files = glob($this->tempDir . '/*_create_posts_table.php');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_posts_table\.php$/', basename($files[0]));

        $instance = require $files[0];
        $this->assertInstanceOf(Migration::class, $instance);

        // Missing or invalid name returns failure.
        $badOut = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $cmd->execute([], $badOut));
    }

    public function testMigrateFreshRefusesProductionWithoutForceAndCancelsOnNoConfirmation(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $app->config()->set('app.env', 'production');

        $conn = new Connection(['database' => 'prime_cms', 'prefix' => 'pc_']);
        $migrator = new Migrator($conn, new Schema($conn));

        // In production without --force: rejected immediately.
        $cmdProd = new MigrateFreshCommand($app, $conn, $migrator);
        $prodOut = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $cmdProd->execute(['--no-interaction'], $prodOut));
        $this->assertStringContainsString('--force', $prodOut->stderr());

        // In local with interactive prompt answered "n": cleanly cancelled without touching DB.
        $app->config()->set('app.env', 'local');
        $prompted = false;
        $cmdLocal = new MigrateFreshCommand($app, $conn, $migrator, static function () use (&$prompted): string {
            $prompted = true;

            return 'n';
        });
        $localOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $cmdLocal->execute([], $localOut));
        $this->assertTrue($prompted);
        $this->assertStringContainsString('Opération annulée', $localOut->stdout());
    }

    public function testSettingsCliGetAndSetRoundTripJsonTypes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $conn->statement('CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL, `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)');
        $settings = new Settings($conn);

        $setCmd = new SettingsSetCommand($settings);
        $getCmd = new SettingsGetCommand($settings);

        $outSet = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $setCmd->execute(['site_meta', '{"posts":10,"active":true}'], $outSet));

        $outGet = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $getCmd->execute(['site_meta'], $outGet));
        $this->assertSame('{"posts":10,"active":true}' . PHP_EOL, $outGet->stdout());

        // Invalid JSON rejected
        $badJson = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $setCmd->execute(['bad_key', 'not-valid-json'], $badJson));
        $this->assertStringContainsString('JSON invalide', $badJson->stderr());

        // Missing setting rejected
        $missing = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $getCmd->execute(['unknown_key'], $missing));
    }

    public function testDbCheckDoesNotLeakPasswordOnFailureAndInstallValidatesRequiredOptions(): void
    {
        $secret = 'CliSecretPass#9876!';
        $conn = new Connection([
            'host' => '127.0.0.1',
            'port' => 65431,
            'database' => 'missing',
            'username' => 'prime',
            'password' => $secret,
        ], debug: false);

        $checkCmd = new DbCheckCommand($conn);
        $out = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $checkCmd->execute([], $out));
        $this->assertStringNotContainsString($secret, $out->stderr());
        $this->assertStringNotContainsString($secret, $out->stdout());

        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $installCmd = new InstallCommand($app->config(), new Installer($app));
        $installOut = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $installCmd->execute([], $installOut));
        $this->assertStringContainsString('--admin-email', $installOut->stderr());
    }
}
