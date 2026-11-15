<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserRepository;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\Security\Csrf;
use Prime\Security\Totp;

final class UsersAndRolesCrudTest extends TestCase
{
    /** @var list<Message> */
    private array $sentMessages = [];

    public function testUsersCrudInvitationAndLastAdminInvariants(): void
    {
        $app = $this->createApp(authenticateUserId: 1);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);

        // 1. Create user with password
        $createRes = $kernel->handle(Request::create('POST', '/admin/users', body: [
            '_csrf_token' => $this->csrf($app),
            'display_name' => 'Marie Éditrice',
            'email' => 'marie@prime-cms.local',
            'locale' => 'fr',
            'status' => 'active',
            'creation_mode' => 'password',
            'password' => 'Emerald!Compass#7291',
            'password_confirmation' => 'Emerald!Compass#7291',
            'roles' => ['2'],
        ]));
        $this->assertSame(302, $createRes->status());
        $this->assertSame('/admin/users', $createRes->header('Location'));

        $marie = $users->findByEmail('marie@prime-cms.local');
        $this->assertNotNull($marie);
        $this->assertSame('Marie Éditrice', $marie->displayName);
        $this->assertSame('editor', $marie->roles[0]->slug);

        // 2. Create user with 48h invitation link
        $inviteRes = $kernel->handle(Request::create('POST', '/admin/users', body: [
            '_csrf_token' => $this->csrf($app),
            'display_name' => 'Paul Invité',
            'email' => 'paul@prime-cms.local',
            'locale' => 'fr',
            'status' => 'active',
            'creation_mode' => 'invite',
            'roles' => ['3'],
        ]));
        $this->assertSame(302, $inviteRes->status());
        $this->assertCount(1, $this->sentMessages);
        $this->assertStringContainsString('/admin/reset-password/', $this->sentMessages[0]->text);

        $paul = $users->findByEmail('paul@prime-cms.local');
        $this->assertNotNull($paul);

        // 3. Filter & search users list (inspect <tbody> so flash toast from invite doesn't interfere)
        $filtered = $kernel->handle(Request::create('GET', '/admin/users', query: ['search' => 'Marie', 'role' => 'editor']));
        $this->assertSame(200, $filtered->status());
        $this->assertSame(1, preg_match('/<tbody>(.*?)<\/tbody>/s', $filtered->body(), $tbodyMatch));
        $this->assertStringContainsString('Marie Éditrice', $tbodyMatch[1]);
        $this->assertStringNotContainsString('Paul Invité', $tbodyMatch[1]);

        // 4. Last active administrator invariant: Camille (#1) is the sole admin
        // Cannot delete self/last admin
        $kernel->handle(Request::create('POST', '/admin/users/1/delete', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertNotNull($users->find(1));

        // Cannot disable self/last admin
        $kernel->handle(Request::create('POST', '/admin/users/1/toggle-status', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertTrue($users->find(1)?->isActive());

        // Cannot strip administrator role from sole admin
        $kernel->handle(Request::create('POST', '/admin/users/1', body: [
            '_csrf_token' => $this->csrf($app),
            'display_name' => 'Camille Admin',
            'email' => 'camille@prime-cms.local',
            'locale' => 'fr',
            'status' => 'active',
            'roles' => ['2'],
        ]));
        $this->assertTrue($users->find(1)?->isAdministrator());

        // 5. Toggle status & bulk delete on non-admin users works
        $kernel->handle(Request::create('POST', '/admin/users/' . $marie->id . '/toggle-status', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertFalse($users->find($marie->id)?->isActive());

        $kernel->handle(Request::create('POST', '/admin/users/bulk', body: [
            '_csrf_token' => $this->csrf($app),
            'bulk_action' => 'delete',
            'selected_ids' => [(string) $paul->id],
        ]));
        $this->assertNull($users->find($paul->id));
    }

    public function testNonAdminUserManagerCannotEscalateOrTouchAdministrators(): void
    {
        // User #3 has custom role "user-manager" (users.view, users.create, users.edit, users.delete, roles.manage) but NOT '*'
        $app = $this->createApp(authenticateUserId: 3);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);

        // Attempt to create a new administrator -> rejected
        $kernel->handle(Request::create('POST', '/admin/users', body: [
            '_csrf_token' => $this->csrf($app),
            'display_name' => 'Rogue Admin',
            'email' => 'rogue@prime-cms.local',
            'locale' => 'fr',
            'status' => 'active',
            'creation_mode' => 'password',
            'password' => 'Emerald!Compass#7291',
            'password_confirmation' => 'Emerald!Compass#7291',
            'roles' => ['1'], // administrator
        ]));
        $this->assertNull($users->findByEmail('rogue@prime-cms.local'));

        // Attempt to disable or delete administrator #1 -> blocked
        $kernel->handle(Request::create('POST', '/admin/users/1/toggle-status', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertTrue($users->find(1)?->isActive());

        $kernel->handle(Request::create('POST', '/admin/users/1/delete', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertNotNull($users->find(1));
    }

    public function testRolesCrudAndProtectionRules(): void
    {
        $app = $this->createApp(authenticateUserId: 1);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var RoleRepository $roles */
        $roles = $app->get(RoleRepository::class);

        // 1. Create custom role
        $res = $kernel->handle(Request::create('POST', '/admin/roles', body: [
            '_csrf_token' => $this->csrf($app),
            'name' => 'Modérateur',
            'slug' => 'moderateur',
            'description' => 'Modère les contenus publiés',
            'capabilities' => ['dashboard.view', 'content.view', 'content.edit_any'],
        ]));
        $this->assertSame(302, $res->status());

        $mod = $roles->findBySlug('moderateur');
        $this->assertNotNull($mod);
        $this->assertFalse($mod->isSystem);
        $this->assertContains('content.edit_any', $mod->capabilities);

        // 2. Administrator role is strictly read-only
        $kernel->handle(Request::create('POST', '/admin/roles/1', body: [
            '_csrf_token' => $this->csrf($app),
            'name' => 'Hacked Admin',
            'capabilities' => ['dashboard.view'],
        ]));
        $adminRole = $roles->find(1);
        $this->assertNotNull($adminRole);
        $this->assertSame('Administrateur', $adminRole->name);
        $this->assertContains('*', $adminRole->capabilities);

        // 3. System role cannot be deleted
        $kernel->handle(Request::create('POST', '/admin/roles/2/delete', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertNotNull($roles->find(2));

        // 4. Custom role with assigned user (#3 has role #4) cannot be deleted
        $kernel->handle(Request::create('POST', '/admin/roles/4/delete', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertNotNull($roles->find(4));

        // 5. Custom role with 0 users CAN be deleted
        $kernel->handle(Request::create('POST', '/admin/roles/' . $mod->id . '/delete', body: ['_csrf_token' => $this->csrf($app)]));
        $this->assertNull($roles->find($mod->id));
    }

    public function testAllEightCliUserAndRoleCommands(): void
    {
        $app = $this->createApp(authenticateUserId: null);
        $console = new ConsoleApplication($app);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var TwoFactorService $twoFactor */
        $twoFactor = $app->get(TwoFactorService::class);

        // 1. role:list
        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'role:list'], $out));
        $this->assertStringContainsString('administrator', $out->stdout());
        $this->assertStringContainsString('Toutes (*)', $out->stdout());

        // 2. user:create (auto-generated strong password)
        $out = new ConsoleOutput(true);
        $code = $console->run([
            'bin/prime', 'user:create',
            '--email=cli@prime-cms.local',
            '--name=CLI User',
            '--role=editor',
        ], $out);
        $this->assertSame(0, $code, $out->stderr());
        $this->assertStringContainsString('Mot de passe généré', $out->stdout());
        $cliUser = $users->findByEmail('cli@prime-cms.local');
        $this->assertNotNull($cliUser);

        // 3. user:list
        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'user:list', '--role=editor'], $out));
        $this->assertStringContainsString('cli@prime-cms.local', $out->stdout());

        // 4. user:password
        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run([
            'bin/prime', 'user:password', 'cli@prime-cms.local', '--password=Topaz!Harbor#3819',
        ], $out));
        $this->assertGreaterThan(1, $users->find($cliUser->id)?->sessionVersion);

        // 5. user:role (--add, --remove, and last-admin protection!)
        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'user:role', 'cli@prime-cms.local', 'contributor', '--add'], $out));
        $this->assertCount(2, $users->find($cliUser->id)?->roles ?? []);

        // Cannot strip administrator role from the sole active administrator via CLI!
        $out = new ConsoleOutput(true);
        $this->assertSame(1, $console->run(['bin/prime', 'user:role', 'camille@prime-cms.local', 'editor'], $out));
        $this->assertTrue($users->find(1)?->isAdministrator());

        // 6. user:disable (blocked on last admin, succeeds on cli user) + user:enable
        $out = new ConsoleOutput(true);
        $this->assertSame(1, $console->run(['bin/prime', 'user:disable', 'camille@prime-cms.local'], $out));
        $this->assertTrue($users->find(1)?->isActive());

        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'user:disable', 'cli@prime-cms.local'], $out));
        $this->assertFalse($users->find($cliUser->id)?->isActive());

        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'user:enable', 'cli@prime-cms.local'], $out));
        $this->assertTrue($users->find($cliUser->id)?->isActive());

        // 7. user:2fa:reset (emergency 2FA recovery)
        $freshCli = $users->find($cliUser->id);
        $this->assertNotNull($freshCli);
        $setup = $twoFactor->generateSetupSecret($freshCli);
        $codes = $twoFactor->confirmAndEnable($freshCli, $setup['secret'], Totp::code($setup['secret'], time()));
        $this->assertNotNull($codes);
        $this->assertTrue($users->find($cliUser->id)?->hasTwoFactor());

        $out = new ConsoleOutput(true);
        $this->assertSame(0, $console->run(['bin/prime', 'user:2fa:reset', 'cli@prime-cms.local'], $out));
        $this->assertFalse($users->find($cliUser->id)?->hasTwoFactor());
    }

    public function testAdminUsersRolesAndProfileRespectSinglePrimaryCtaFormWidthsAndStackedCards(): void
    {
        $app = $this->createApp(authenticateUserId: 1);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $extractMain = function (string $html): string {
            $this->assertSame(1, preg_match('/<main\b[^>]*>(.*?)<\/main>/s', $html, $m));

            return $m[1];
        };

        // 1. Profile screen: each tab panel has strictly 1 primary coral CTA and comfortable 560px form width
        $profileRes = $kernel->handle(Request::create('GET', '/admin/profile', query: ['tab' => 'security']));
        $this->assertSame(200, $profileRes->status());
        $profileMain = $extractMain($profileRes->body());
        $this->assertStringContainsString('pc-form-narrow', $profileMain);
        $this->assertStringContainsString('Mettre à jour le mot de passe', $profileMain);
        $this->assertStringContainsString('Activer la double authentification', $profileMain);

        // Extract both tab panels and assert strictly 1 pc-btn--primary button in each
        $infoPos = strpos($profileMain, 'id="profile-tabs-panel-info"');
        $securityPos = strpos($profileMain, 'id="profile-tabs-panel-security"');
        $this->assertIsInt($infoPos);
        $this->assertIsInt($securityPos);
        $infoPanel = substr($profileMain, $infoPos, $securityPos - $infoPos);
        $securityPanel = substr($profileMain, $securityPos);
        $this->assertSame(1, substr_count($infoPanel, 'pc-btn--primary'));
        $this->assertSame(1, substr_count($securityPanel, 'pc-btn--primary'));

        // 2. Users index (non-empty + filtered empty state): strictly 1 primary CTA and stacked mobile cards
        $usersRes = $kernel->handle(Request::create('GET', '/admin/users'));
        $this->assertSame(200, $usersRes->status());
        $usersMain = $extractMain($usersRes->body());
        $this->assertSame(1, substr_count($usersMain, 'pc-btn--primary'));
        $this->assertStringContainsString('pc-table--stacked', $usersMain);
        $this->assertStringContainsString('data-prime-label="Rôles"', $usersMain);
        $this->assertStringContainsString('data-prime-label="Statut"', $usersMain);
        $this->assertStringContainsString('data-prime-label="Dernière connexion"', $usersMain);

        $emptyUsersRes = $kernel->handle(Request::create('GET', '/admin/users', query: ['search' => 'zzznomatch999']));
        $this->assertSame(200, $emptyUsersRes->status());
        $this->assertSame(1, substr_count($extractMain($emptyUsersRes->body()), 'pc-btn--primary'));

        // 3. Users create & edit forms: single primary CTA and constrained two-column grid (<= 880px)
        $createRes = $kernel->handle(Request::create('GET', '/admin/users/create'));
        $this->assertSame(200, $createRes->status());
        $createMain = $extractMain($createRes->body());
        $this->assertSame(1, substr_count($createMain, 'pc-btn--primary'));
        $this->assertStringContainsString('pc-form-grid--aside', $createMain);

        $editRes = $kernel->handle(Request::create('GET', '/admin/users/1/edit'));
        $this->assertSame(200, $editRes->status());
        $editMain = $extractMain($editRes->body());
        $this->assertSame(1, substr_count($editMain, 'pc-btn--primary'));
        $this->assertStringContainsString('pc-form-grid--aside', $editMain);

        // 4. Roles index & edit: single primary CTA, stacked mobile cards, wide identity + full-width matrix
        $rolesRes = $kernel->handle(Request::create('GET', '/admin/roles'));
        $this->assertSame(200, $rolesRes->status());
        $rolesMain = $extractMain($rolesRes->body());
        $this->assertSame(1, substr_count($rolesMain, 'pc-btn--primary'));
        $this->assertStringContainsString('pc-table--stacked', $rolesMain);
        $this->assertStringContainsString('data-prime-label="Permissions"', $rolesMain);

        $roleFormRes = $kernel->handle(Request::create('GET', '/admin/roles/create'));
        $this->assertSame(200, $roleFormRes->status());
        $roleFormMain = $extractMain($roleFormRes->body());
        $this->assertSame(1, substr_count($roleFormMain, 'pc-btn--primary'));
        $this->assertStringContainsString('pc-form-wide', $roleFormMain);
        $this->assertStringContainsString('pc-cap-matrix', $roleFormMain);
    }

    private function createApp(?int $authenticateUserId): Application
    {
        $this->sentMessages = [];

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'local');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.url', 'https://prime.example');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('q', 32)));
        $app->config()->set('mail.driver', 'log');
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $messages = &$this->sentMessages;
        $app->container()->instance(MailerInterface::class, new class ($messages) implements MailerInterface {
            /** @param list<Message> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function send(Message $message): void
            {
                $this->messages[] = $message;
            }
        });

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');

        $now = '2026-11-15 12:00:00';
        $pdo->exec("INSERT INTO pc_roles VALUES (1, 'administrator', 'Administrateur', 'Accès complet', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles VALUES (2, 'editor', 'Éditeur', 'Gestion éditoriale', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles VALUES (3, 'contributor', 'Contributeur', 'Brouillons', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_roles VALUES (4, 'user-manager', 'Gestionnaire comptes', 'Custom non-admin', 0, '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO pc_role_capabilities VALUES (1, '*')");
        $pdo->exec("INSERT INTO pc_role_capabilities VALUES (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.publish')");
        $pdo->exec("INSERT INTO pc_role_capabilities VALUES (3, 'dashboard.view'), (3, 'content.view')");
        $pdo->exec("INSERT INTO pc_role_capabilities VALUES (4, 'dashboard.view'), (4, 'users.view'), (4, 'users.create'), (4, 'users.edit'), (4, 'users.delete'), (4, 'roles.manage')");

        $hash = password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'camille@prime-cms.local', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([3, 'manager@prime-cms.local', $hash, 'Jean Manager', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles VALUES (1, 1), (3, 4)');

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        if ($authenticateUserId !== null) {
            /** @var UserRepository $users */
            $users = $app->get(UserRepository::class);
            /** @var Auth $auth */
            $auth = $app->get(Auth::class);
            $user = $users->find($authenticateUserId);
            if ($user !== null) {
                $auth->login($user);
            }
        }

        return $app;
    }

    private function csrf(Application $app): string
    {
        /** @var Csrf $csrf */
        $csrf = $app->get(Csrf::class);

        return $csrf->token();
    }
}
