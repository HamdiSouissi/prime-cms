<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Console\Commands\DbCheckCommand;
use Prime\Console\Commands\InstallCommand;
use Prime\Console\Commands\MigrateStatusCommand;
use Prime\Console\Commands\SettingsGetCommand;
use Prime\Console\Commands\SettingsSetCommand;
use Prime\Console\ConsoleOutput;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\Exception\InstallException;
use Prime\Install\InstallCheckerInterface;
use Prime\Install\InstallConfig;
use Prime\Install\Installer;
use Prime\Kernel\Application;
use Prime\Kernel\Env;
use Prime\Security\Csrf;
use Prime\Settings\Settings;

#[Group('database')]
final class InstallerIntegrationTest extends DatabaseTestCase
{
    /**
     * @var list<string>
     */
    private array $tempWorkspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->tempWorkspaces as $dir) {
            $this->removeWorkspace($dir);
        }
        $this->tempWorkspaces = [];

        parent::tearDown();
    }

    public function testFullWebWizardEndToEndRedirectsCsrfInstallsDatabaseAndLocksInstallRoutes(): void
    {
        $workspace = $this->createIsolatedWorkspace();
        $app = $this->bootWorkspaceApp($workspace);

        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);
        $hookFired = false;
        $hooks->on('install.completed', static function () use (&$hookFired): void {
            $hookFired = true;
        });

        /** @var Kernel $http */
        $http = $app->get(Kernel::class);

        // 1. Before install: `/` and `/admin` redirect to `/install`; `/health` stays 200.
        $homeBefore = $http->handle(Request::create('GET', '/'));
        $this->assertSame(302, $homeBefore->status());
        $this->assertSame('/install', $homeBefore->header('Location'));

        $adminBefore = $http->handle(Request::create('GET', '/admin'));
        $this->assertSame(302, $adminBefore->status());
        $this->assertSame('/install', $adminBefore->header('Location'));

        $healthBefore = $http->handle(Request::create('GET', '/health'));
        $this->assertSame(200, $healthBefore->status());

        // 2. POST without CSRF token returns 419.
        $noCsrf = $http->handle(Request::create('POST', '/install', body: ['wizard_locale' => 'fr']));
        $this->assertSame(419, $noCsrf->status());

        /** @var Csrf $csrf */
        $csrf = $app->get(Csrf::class);
        $token = $csrf->token();

        // 3. Step 1 — Welcome
        $welcomeGet = $http->handle(Request::create('GET', '/install'));
        $this->assertSame(200, $welcomeGet->status());
        $this->assertStringContainsString('Bienvenue dans Prime CMS', $welcomeGet->body());

        $welcomePost = $http->handle(Request::create('POST', '/install', body: [
            Csrf::FIELD_NAME => $token,
            'wizard_locale' => 'fr',
        ]));
        $this->assertSame(302, $welcomePost->status());
        $this->assertSame('/install/requirements', $welcomePost->header('Location'));

        // 4. Step 2 — Requirements (GET check)
        $reqGet = $http->handle(Request::create('GET', '/install/requirements'));
        $this->assertSame(200, $reqGet->status());

        // 5. Step 3 — Database
        $dbPost = $http->handle(Request::create('POST', '/install/database', body: [
            Csrf::FIELD_NAME => $token,
            'db_host' => $this->dbConfig['host'],
            'db_port' => (string) $this->dbConfig['port'],
            'db_database' => $this->dbConfig['database'],
            'db_username' => $this->dbConfig['username'],
            'db_password' => $this->dbConfig['password'],
            'db_prefix' => $this->prefix,
        ]));
        $this->assertSame(302, $dbPost->status());
        $this->assertSame('/install/site', $dbPost->header('Location'));

        // 6. Step 4 — Site
        $sitePost = $http->handle(Request::create('POST', '/install/site', body: [
            Csrf::FIELD_NAME => $token,
            'site_name' => 'Atelier Lumière',
            'site_url' => 'https://lumiere.example.fr',
            'site_locale' => 'fr',
            'timezone' => 'Europe/Paris',
        ]));
        $this->assertSame(302, $sitePost->status());
        $this->assertSame('/install/admin', $sitePost->header('Location'));

        // 7. Step 5 — Admin
        $adminPass = 'Ciel!Mandarine#8429';
        $adminPost = $http->handle(Request::create('POST', '/install/admin', body: [
            Csrf::FIELD_NAME => $token,
            'display_name' => 'Camille Laurent',
            'email' => 'camille@lumiere.example.fr',
            'password' => $adminPass,
            'password_confirmation' => $adminPass,
        ]));
        $this->assertSame(302, $adminPost->status());
        $this->assertSame('/install/run', $adminPost->header('Location'));

        // 8. Step 6 — Summary must NEVER contain DB password or admin password!
        $runGet = $http->handle(Request::create('GET', '/install/run'));
        $this->assertSame(200, $runGet->status());
        $this->assertStringContainsString('Atelier Lumière', $runGet->body());
        $this->assertStringNotContainsString($adminPass, $runGet->body());
        if ($this->dbConfig['password'] !== '' && $this->dbConfig['password'] !== $this->dbConfig['username']) {
            $this->assertStringNotContainsString($this->dbConfig['password'], $runGet->body());
        }

        // Execute installation!
        $runPost = $http->handle(Request::create('POST', '/install/run', body: [
            Csrf::FIELD_NAME => $token,
        ]));
        $this->assertSame(302, $runPost->status());
        $this->assertSame('/install/done', $runPost->header('Location'));
        $this->assertTrue($hookFired);

        // 9. Verify `.env`, `storage/installed.lock`, DB tables, admin hash, and core settings!
        $this->assertFileExists($workspace . '/.env');
        $this->assertFileExists($workspace . '/storage/installed.lock');

        $parsedEnv = Env::parse((string) file_get_contents($workspace . '/.env'));
        $this->assertStringStartsWith('base64:', $parsedEnv['APP_KEY']);
        $this->assertSame('production', $parsedEnv['APP_ENV']);
        $this->assertSame('false', $parsedEnv['APP_DEBUG']);
        $this->assertSame($this->prefix, $parsedEnv['DB_PREFIX']);

        $adminRow = $this->connection->table('users')
            ->where('email', 'camille@lumiere.example.fr')
            ->first();
        $this->assertIsArray($adminRow);
        $this->assertSame('Camille Laurent', $adminRow['display_name']);
        $this->assertSame('active', $adminRow['status']);
        $this->assertTrue(password_verify($adminPass, (string) $adminRow['password_hash']));

        $settings = new Settings($this->connection);
        $this->assertSame('Atelier Lumière', $settings->get('site_name'));
        $this->assertSame('https://lumiere.example.fr', $settings->get('site_url'));
        $this->assertSame('fr', $settings->get('site_locale'));
        $this->assertSame('Europe/Paris', $settings->get('timezone'));
        $this->assertIsString($settings->get('installed_at'));
        $this->assertSame(Application::VERSION, $settings->get('prime_version'));
        // The keys the admin screens and the site read (a fresh install must not show « Prime CMS »).
        $this->assertSame('Atelier Lumière', $settings->get('site.name'));
        $this->assertSame('fr', $settings->get('language.site'));
        $this->assertSame('Europe/Paris', $settings->get('general.timezone'));
        $this->assertNotSame('', (string) $settings->get('site.admin_email'), 'requis par Réglages › Général');
        // Plugins shipped as active (`plugins.default_active`) are active after the wizard.
        $this->assertSame('active', $this->connection->table('plugins')->where('slug', '=', 'prime-starter')->value('status'));

        /** @var InstallCheckerInterface $checker */
        $checker = $app->get(InstallCheckerInterface::class);
        $this->assertTrue($checker->isInstalled());

        // 10. Step 7 — One-time `/install/done` works once, then locks to 404 forever!
        $doneGet = $http->handle(Request::create('GET', '/install/done'));
        $this->assertSame(200, $doneGet->status());
        $this->assertStringContainsString('Prime CMS est installé', $doneGet->body());

        $doneRefresh = $http->handle(Request::create('GET', '/install/done'));
        $this->assertSame(404, $doneRefresh->status());

        $installLocked = $http->handle(Request::create('GET', '/install'));
        $this->assertSame(404, $installLocked->status());

        // 11. `/` now serves the site (200) instead of redirecting to `/install`.
        $homeAfter = $http->handle(Request::create('GET', '/'));
        $this->assertSame(200, $homeAfter->status());
    }

    public function testInstallerRefusesPrefixCollisionAndDoesNotCreateLockFileOnFailure(): void
    {
        $schema = new Schema($this->connection);
        $schema->create('settings', static function (Blueprint $table): void {
            $table->id();
        });

        $workspace = $this->createIsolatedWorkspace();
        $app = $this->bootWorkspaceApp($workspace);
        $installer = new Installer($app);

        $config = new InstallConfig(
            dbHost: $this->dbConfig['host'],
            dbPort: $this->dbConfig['port'],
            dbDatabase: $this->dbConfig['database'],
            dbUsername: $this->dbConfig['username'],
            dbPassword: $this->dbConfig['password'],
            dbPrefix: $this->prefix,
            siteName: 'Collision Test',
            siteUrl: 'https://collision.example.fr',
            siteLocale: 'fr',
            timezone: 'Europe/Paris',
            adminDisplayName: 'Admin',
            adminEmail: 'admin@collision.example.fr',
            adminPassword: 'Saffron!Horizon#2026',
        );

        $caught = null;
        try {
            $installer->install($config);
        } catch (InstallException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InstallException::class, $caught);
        $this->assertSame('database', $caught->step());
        $this->assertFileDoesNotExist($workspace . '/storage/installed.lock');
    }

    public function testCliInstallAndDatabaseCommandsWorkEndToEndOnRealDatabase(): void
    {
        $workspace = $this->createIsolatedWorkspace();
        $app = $this->bootWorkspaceApp($workspace);

        $installCmd = new InstallCommand($app->config(), new Installer($app));
        $installOut = new ConsoleOutput(buffered: true);

        $code = $installCmd->execute([
            '--db-host=' . $this->dbConfig['host'],
            '--db-port=' . (string) $this->dbConfig['port'],
            '--db-name=' . $this->dbConfig['database'],
            '--db-user=' . $this->dbConfig['username'],
            '--db-password=' . $this->dbConfig['password'],
            '--db-prefix=' . $this->prefix,
            '--site-name=Prime CLI Site',
            '--site-url=https://cli.example.fr',
            '--site-locale=fr',
            '--timezone=Europe/Paris',
            '--admin-name=CLI Admin',
            '--admin-email=cli@example.fr',
            '--admin-password=Saffron!Horizon#2026',
        ], $installOut);

        $this->assertSame(0, $code, $installOut->stderr());
        $this->assertStringContainsString('Prime CMS installé avec succès', $installOut->stdout());
        $this->assertFileExists($workspace . '/storage/installed.lock');
        $this->assertFileExists($workspace . '/.env');

        // Verify `db:check`, `migrate:status`, `settings:get`, and `settings:set` against the installed DB!
        $checkOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, (new DbCheckCommand($this->connection))->execute([], $checkOut));
        $this->assertStringContainsString('Connexion à la base de données réussie', $checkOut->stdout());

        $migrator = new Migrator($this->connection, new Schema($this->connection), [dirname(__DIR__, 2) . '/database/migrations']);
        $statusOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, (new MigrateStatusCommand($migrator))->execute([], $statusOut));
        $this->assertStringContainsString('2026_11_15_000001_create_settings_table', $statusOut->stdout());
        $this->assertStringContainsString('Exécutée', $statusOut->stdout());

        $settings = new Settings($this->connection);
        $getOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, (new SettingsGetCommand($settings))->execute(['site_name'], $getOut));
        $this->assertSame('"Prime CLI Site"' . PHP_EOL, $getOut->stdout());

        $setOut = new ConsoleOutput(buffered: true);
        $this->assertSame(0, (new SettingsSetCommand($settings))->execute(['site_tagline', '"Rapide et moderne"'], $setOut));
        $this->assertSame('Rapide et moderne', $settings->get('site_tagline'));
    }

    private function createIsolatedWorkspace(): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/prime_install_it_' . uniqid('', true);
        mkdir($dir . '/storage/cache', 0755, true);
        mkdir($dir . '/storage/logs', 0755, true);
        mkdir($dir . '/storage/sessions', 0755, true);
        mkdir($dir . '/storage/tmp', 0755, true);
        mkdir($dir . '/content/uploads', 0755, true);
        symlink($repoRoot . '/content/themes', $dir . '/content/themes');
        symlink($repoRoot . '/content/plugins', $dir . '/content/plugins');

        foreach (['config', 'database', 'resources', 'public'] as $link) {
            symlink($repoRoot . '/' . $link, $dir . '/' . $link);
        }

        $this->tempWorkspaces[] = $dir;

        return $dir;
    }

    private function bootWorkspaceApp(string $workspace): Application
    {
        $app = new Application($workspace, loadEnv: false);
        $app->config()->set('app.debug', false);
        $app->config()->set('logging.path', $workspace . '/storage/logs');
        $app->boot();

        return $app;
    }

    private function removeWorkspace(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeWorkspace($path);
            }
        }

        @rmdir($dir);
    }
}
