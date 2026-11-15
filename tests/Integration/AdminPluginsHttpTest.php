<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\Notice;
use Prime\Admin\Notices;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Security\Csrf;
use Prime\Tests\Support\MysqlEmulatingPdo;

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

    private function createApp(string $authenticateAs = 'admin', bool $safeMode = false): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'local');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app->config()->set('plugins.safe_mode', $safeMode);
        $app->boot();

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
