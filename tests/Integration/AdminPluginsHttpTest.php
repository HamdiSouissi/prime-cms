<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notice;
use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Auth\Capabilities;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Kernel\Autoloader;
use Prime\Log\Logger;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\DependencyResolver;
use Prime\Plugins\FatalLoadGuard;
use Prime\Plugins\ManifestValidator;
use Prime\Plugins\PluginManager;
use Prime\Plugins\PluginRepository;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use Prime\View\View;

final class AdminPluginsHttpTest extends TestCase
{
    public function testPluginsPageRendersSingleCoralCtaZeroInlineScriptsAndNoticeDismissal(): void
    {
        $app = $this->createApp(authenticateAs: 'admin', safeMode: false);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Notices $notices */
        $notices = $app->get(Notices::class);

        $notices->add(new Notice(
            id: 'test.welcome.banner',
            type: Notice::TYPE_INFO,
            title: 'Notification de test',
            message: 'Message persistant visible dans le dashboard.',
            capability: null,
            dismissible: true,
            source: 'test',
        ));

        $res = $kernel->handle(Request::create('GET', '/admin/plugins'));
        $body = $res->body();

        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Ajouter un plugin', $body);
        $this->assertStringContainsString('Hello Prime', $body);
        $this->assertStringContainsString('Notification de test', $body);
        $this->assertStringContainsString('data-prime-notice-badge', $body);

        // Design system §8 rule: strictly ONE coral primary button (`pc-btn--primary`) on the screen!
        $primaryButtonCount = substr_count($body, 'pc-btn--primary');
        $this->assertSame(1, $primaryButtonCount, 'Expected strictly one coral primary button on /admin/plugins.');

        // Dismiss persistent notice via CSRF-protected POST
        /** @var Session $session */
        $session = $app->get(Session::class);
        $token = (new Csrf($session))->token();

        $dismissRes = $kernel->handle(Request::create(
            'POST',
            '/admin/notices/test.welcome.banner/dismiss',
            body: ['_csrf_token' => $token],
            headers: ['Accept' => 'application/json'],
        ));
        $this->assertSame(200, $dismissRes->status());
        $this->assertStringContainsString('"ok":true', $dismissRes->body());

        // Subsequent render hides the dismissed notice for this user
        $afterRes = $kernel->handle(Request::create('GET', '/admin/plugins'));
        $this->assertStringNotContainsString('Notification de test', $afterRes->body());
    }

    public function testSafeModeBannerIsDisplayedAndContributorGets403(): void
    {
        $safeApp = $this->createApp(authenticateAs: 'admin', safeMode: true);
        /** @var Kernel $safeKernel */
        $safeKernel = $safeApp->get(Kernel::class);

        $res = $safeKernel->handle(Request::create('GET', '/admin/plugins'));
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Mode sans échec actif (PRIME_SAFE_MODE=true)', $res->body());

        $contribApp = $this->createApp(authenticateAs: 'contributor', safeMode: false);
        /** @var Kernel $contribKernel */
        $contribKernel = $contribApp->get(Kernel::class);
        $this->assertSame(403, $contribKernel->handle(Request::create('GET', '/admin/plugins'))->status());
    }

    public function testDashboardRenders200WithHelloPrimeActiveAndShowsShortcut(): void
    {
        $tmpAssets = sys_get_temp_dir() . '/prime_hp_dash_assets_' . bin2hex(random_bytes(4));
        mkdir($tmpAssets, 0755, true);

        try {
            $app = $this->createApp(authenticateAs: 'admin', safeMode: false);
            $app->container()->instance(AssetPublisher::class, new AssetPublisher($tmpAssets, '/assets/plugins'));

            /** @var PluginManager $manager */
            $manager = $app->get(PluginManager::class);
            $manager->activate('hello-prime');

            /** @var Kernel $kernel */
            $kernel = $app->get(Kernel::class);
            $res = $kernel->handle(Request::create('GET', '/admin'));
            $body = $res->body();

            $this->assertSame(200, $res->status());
            $this->assertStringContainsString('Tableau de bord', $body);
            $this->assertStringContainsString('Hello Prime', $body);
            $this->assertStringContainsString('/admin/hello-prime', $body);
        } finally {
            AssetPublisher::deleteDirectory($tmpAssets);
        }
    }

    public function testDashboardIgnoresInvalidPluginFilterPayloadsAndLogsWarnings(): void
    {
        $tmpLogs = sys_get_temp_dir() . '/prime_filter_logs_' . bin2hex(random_bytes(4));
        mkdir($tmpLogs, 0755, true);

        try {
            $app = $this->createApp(authenticateAs: 'admin', safeMode: false, logDir: $tmpLogs);
            /** @var Hooks $hooks */
            $hooks = $app->get(Hooks::class);

            $hooks->withOwner('broken-filter-plugin', static function () use ($hooks): void {
                // Missing `title` (uses `label` instead), bad type, unsafe URL, plus one valid entry
                $hooks->filter('admin.dashboard.shortcuts', static function (mixed $shortcuts): mixed {
                    if (!is_array($shortcuts)) {
                        return $shortcuts;
                    }
                    $shortcuts[] = ['label' => 'Clé invalide', 'url' => '/admin/bad'];
                    $shortcuts[] = ['title' => 12345, 'url' => '/admin/bad-type'];
                    $shortcuts[] = ['title' => 'XSS', 'url' => 'javascript:alert(1)'];
                    $shortcuts[] = 'not-an-array-entry';
                    $shortcuts[] = ['title' => 'Raccourci Valide', 'description' => 'OK', 'icon' => 'sparkles', 'accent' => 'violet', 'url' => '/admin/valid-shortcut'];

                    return $shortcuts;
                });

                // Non-array returned for stats -> core stats preserved!
                $hooks->filter('admin.dashboard.stats', static fn (): string => 'corrupted-stats');

                // Invalid entries injected in create menu, command palette, assets, menu, and notices
                $hooks->filter('admin.create.items', static function (mixed $items): mixed {
                    if (is_array($items)) {
                        $items[] = ['label' => 'Sans ID', 'url' => '/admin/no-id'];
                    }

                    return $items;
                });
                $hooks->filter('admin.command_palette', static function (mixed $entries): mixed {
                    if (is_array($entries)) {
                        $entries[] = ['id' => 'bad.cmd', 'label' => 'Bad KW', 'url' => '/admin/x', 'keywords' => 'not-array'];
                    }

                    return $entries;
                });
                $hooks->filter('admin.assets', static function (mixed $assets): mixed {
                    if (is_array($assets) && isset($assets['css']) && is_array($assets['css'])) {
                        $assets['css'][] = 'javascript:alert(1)';
                    }

                    return $assets;
                });
                $hooks->filter('admin.menu', static fn (): string => 'broken-menu');
                $hooks->filter('admin.notices', static function (mixed $notices): mixed {
                    if (is_array($notices)) {
                        $notices[] = ['id' => 'bad', 'type' => 'unknown-type', 'title' => 42];
                    }

                    return $notices;
                });
            });

            /** @var Kernel $kernel */
            $kernel = $app->get(Kernel::class);
            $res = $kernel->handle(Request::create('GET', '/admin'));
            $body = $res->body();

            $this->assertSame(200, $res->status());
            $this->assertStringContainsString('Tableau de bord', $body);
            $this->assertStringContainsString('Raccourci Valide', $body);
            $this->assertStringNotContainsString('Clé invalide', $body);
            $this->assertStringNotContainsString('javascript:alert(1)', $body);

            $logFiles = glob($tmpLogs . '/prime-*.log');
            $this->assertIsArray($logFiles);
            $this->assertNotEmpty($logFiles);
            $logContent = (string) file_get_contents($logFiles[0]);
            $this->assertStringContainsString('WARNING', $logContent);
            $this->assertStringContainsString('broken-filter-plugin', $logContent);
            $this->assertStringContainsString('admin.dashboard.shortcuts', $logContent);
            $this->assertStringContainsString('admin.dashboard.stats', $logContent);
        } finally {
            AssetPublisher::deleteDirectory($tmpLogs);
        }
    }

    public function testPluginsPageHidesServerPathsAndShowsConsolidatedErroredNotice(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prime_err_http_' . bin2hex(random_bytes(4));
        $pluginsDir = $tmpDir . '/plugins';
        mkdir($pluginsDir . '/http-boot-err/src', 0755, true);
        mkdir($pluginsDir . '/http-err-two/src', 0755, true);
        mkdir($pluginsDir . '/http-err-three/src', 0755, true);
        mkdir($tmpDir . '/logs', 0755, true);
        mkdir($tmpDir . '/public-assets', 0755, true);

        $suffix = ucfirst(bin2hex(random_bytes(4)));
        $ns1 = 'HttpBootErr' . $suffix;
        $ns2 = 'HttpErrTwo' . $suffix;
        $ns3 = 'HttpErrThree' . $suffix;

        $writeBrokenPlugin = static function (string $dir, string $slug, string $name, string $ns, string $body): void {
            file_put_contents($dir . '/plugin.json', (string) json_encode([
                'slug' => $slug,
                'name' => $name,
                'description' => 'Fixture HTTP isolée.',
                'version' => '1.0.0',
                'author' => ['name' => 'Prime QA'],
                'license' => 'proprietary',
                'namespace' => $ns . '\\',
                'main' => $ns . '\\Plugin',
                'requires' => ['prime' => '^0.5', 'php' => '^8.2'],
            ], JSON_UNESCAPED_SLASHES));
            file_put_contents($dir . '/src/Plugin.php', $body);
        };

        $writeBrokenPlugin(
            $pluginsDir . '/http-boot-err',
            'http-boot-err',
            'HTTP Boot Error',
            $ns1,
            "<?php\ndeclare(strict_types=1);\nnamespace {$ns1};\nuse Prime\\Plugins\\AbstractPlugin;\nuse Prime\\Plugins\\PluginContext;\nuse RuntimeException;\nfinal class Plugin extends AbstractPlugin\n{\n    public function boot(PluginContext \$context): void\n    {\n        throw new RuntimeException('Erreur critique sur ' . __FILE__);\n    }\n}\n",
        );
        $writeBrokenPlugin(
            $pluginsDir . '/http-err-two',
            'http-err-two',
            'HTTP Error Two',
            $ns2,
            "<?php\ndeclare(strict_types=1);\nnamespace {$ns2};\nuse Prime\\Plugins\\AbstractPlugin;\nuse Prime\\Plugins\\PluginContext;\nuse RuntimeException;\nfinal class Plugin extends AbstractPlugin\n{\n    public function boot(PluginContext \$context): void\n    {\n        throw new RuntimeException('Échec plugin 2');\n    }\n}\n",
        );
        $writeBrokenPlugin(
            $pluginsDir . '/http-err-three',
            'http-err-three',
            'HTTP Error Three',
            $ns3,
            "<?php\ndeclare(strict_types=1);\nnamespace {$ns3};\nuse Prime\\Plugins\\AbstractPlugin;\nuse Prime\\Plugins\\PluginContext;\nuse RuntimeException;\nfinal class Plugin extends AbstractPlugin\n{\n    public function boot(PluginContext \$context): void\n    {\n        throw new RuntimeException('Échec plugin 3');\n    }\n}\n",
        );

        try {
            // 1. Single errored plugin -> named notice & zero server base/plugin path in HTML
            $app = $this->createApp(
                authenticateAs: 'admin',
                safeMode: false,
                logDir: $tmpDir . '/logs',
                pluginsAbsPath: $pluginsDir,
                publicAssetsPath: $tmpDir . '/public-assets',
                witnessFile: $tmpDir . '/loading.json',
            );

            /** @var PluginRepository $repo */
            $repo = $app->get(PluginRepository::class);
            $repo->markActive('http-boot-err', '1.0.0', 'h1');

            /** @var PluginManager $manager */
            $manager = $app->get(PluginManager::class);
            $manager->bootActivePlugins();

            /** @var Kernel $kernel */
            $kernel = $app->get(Kernel::class);
            $singleRes = $kernel->handle(Request::create('GET', '/admin/plugins'));
            $singleHtml = $singleRes->body();

            $this->assertSame(200, $singleRes->status());
            $this->assertStringContainsString('Le plugin HTTP Boot Error a été désactivé suite à une erreur', $singleHtml);
            $this->assertStringContainsString('/admin/plugins?status=errored', $singleHtml);
            $this->assertStringContainsString('RuntimeException:', $singleHtml);
            $this->assertStringContainsString('(src/Plugin.php:11)', $singleHtml);
            $this->assertStringNotContainsString($app->basePath(), $singleHtml);
            $this->assertStringNotContainsString($tmpDir, $singleHtml);

            // 2. Four errored plugins -> strictly ONE consolidated summary notice + exact bell badge
            /** @var FatalLoadGuard $guard */
            $guard = $app->get(FatalLoadGuard::class);
            $guard->markLoading('crashed-prev', 'boot');

            $app2 = $this->createApp(
                authenticateAs: 'admin',
                safeMode: false,
                logDir: $tmpDir . '/logs',
                pluginsAbsPath: $pluginsDir,
                publicAssetsPath: $tmpDir . '/public-assets',
                witnessFile: $tmpDir . '/loading.json',
            );
            /** @var PluginRepository $repo2 */
            $repo2 = $app2->get(PluginRepository::class);
            $repo2->markActive('http-boot-err', '1.0.0', 'h1');
            $repo2->markActive('http-err-two', '1.0.0', 'h2');
            $repo2->markActive('http-err-three', '1.0.0', 'h3');

            /** @var PluginManager $manager2 */
            $manager2 = $app2->get(PluginManager::class);
            $manager2->bootActivePlugins();

            /** @var Kernel $kernel2 */
            $kernel2 = $app2->get(Kernel::class);
            $multiRes = $kernel2->handle(Request::create('GET', '/admin/plugins?status=errored'));
            $multiHtml = $multiRes->body();

            $this->assertSame(200, $multiRes->status());
            $this->assertStringContainsString('4 plugins ont été désactivés suite à une erreur', $multiHtml);
            $this->assertStringContainsString('Voir les plugins en erreur', $multiHtml);
            $this->assertStringContainsString('data-prime-notice-badge aria-hidden="true">1</span>', $multiHtml);
            $this->assertSame(1, substr_count($multiHtml, 'class="pc-alert pc-alert--danger pc-notice"'));
            $this->assertStringContainsString('data-prime-notice="plugins.errored.summary"', $multiHtml);
            $this->assertStringContainsString('Réessayer l’activation', $multiHtml);
            $this->assertStringNotContainsString($app2->basePath(), $multiHtml);
            $this->assertStringNotContainsString($tmpDir, $multiHtml);
        } finally {
            AssetPublisher::deleteDirectory($tmpDir);
        }
    }

    private function createApp(
        string $authenticateAs = 'admin',
        bool $safeMode = false,
        ?string $logDir = null,
        ?string $pluginsAbsPath = null,
        ?string $publicAssetsPath = null,
        ?string $witnessFile = null,
    ): Application {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'local');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app->config()->set('plugins.safe_mode', $safeMode);
        if ($logDir !== null) {
            $app->config()->set('logging.path', $logDir);
        }
        $app->boot();

        if ($logDir !== null) {
            $app->container()->instance(Logger::class, new Logger($logDir, Logger::DEBUG, 'prime'));
        }
        if ($publicAssetsPath !== null) {
            $app->container()->instance(AssetPublisher::class, new AssetPublisher($publicAssetsPath, '/assets/plugins'));
        }
        if ($witnessFile !== null) {
            $app->container()->instance(FatalLoadGuard::class, new FatalLoadGuard($witnessFile));
        }
        if ($pluginsAbsPath !== null) {
            $app->container()->singleton(PluginManager::class, static function () use ($app, $pluginsAbsPath, $safeMode): PluginManager {
                $autoloader = new Autoloader();
                $autoloader->register();

                return new PluginManager(
                    app: $app,
                    pluginsPath: $pluginsAbsPath,
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
                    safeMode: $safeMode,
                );
            });
        }

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) UNIQUE, value LONGTEXT, autoload INTEGER DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("CREATE TABLE pc_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(191) UNIQUE, source VARCHAR(64) DEFAULT 'core', batch INTEGER, ran_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');

        $now = '2026-11-15 12:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Accès complet', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (2, 'contributor', 'Contributeur', 'Brouillons', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*'), (2, 'dashboard.view')");

        $hash = password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'admin@prime.local', $hash, 'Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'contrib@prime.local', $hash, 'Contrib', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        $user = $users->find($authenticateAs === 'admin' ? 1 : 2);
        $this->assertNotNull($user);
        $auth->login($user);

        return $app;
    }
}
