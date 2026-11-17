<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Admin\SystemStatus;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Install\InstallCheckerInterface;
use Prime\Jobs\JobRepository;
use Prime\Kernel\Application;
use Prime\Mail\Mailer;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Phase 9 screens: registry-driven settings (Général, Sécurité, E-mails) and « Outils »
 * (tâches planifiées, file d'attente, état du système), over the real HTTP kernel.
 */
final class AdminSettingsToolsTest extends TestCase
{
    private string $pageCacheDir;

    private Application $app;

    private string $databaseFile;

    protected function setUp(): void
    {
        $this->pageCacheDir = sys_get_temp_dir() . '/prime_tools_pages_' . uniqid('', true);
        $this->databaseFile = sys_get_temp_dir() . '/prime_tools_db_' . uniqid('', true) . '.sqlite';
        $this->app = $this->createApp();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pageCacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pageCacheDir);
        @unlink($this->databaseFile);
    }

    public function testSettingsLandingRedirectsAndEachScreenRendersItsRegistryFields(): void
    {
        $landing = $this->kernel()->handle(Request::create('GET', '/admin/settings'));
        $this->assertSame(302, $landing->status());
        $this->assertSame('/admin/settings/general', $landing->header('Location'));

        $general = $this->kernel()->handle(Request::create('GET', '/admin/settings/general'))->body();
        $this->assertStringContainsString('name="settings[site.name]"', $general);
        $this->assertStringContainsString('name="settings[language.site]"', $general);
        $this->assertStringContainsString('name="settings[advanced.maintenance]"', $general);
        // Hidden-type settings never reach the form.
        $this->assertStringNotContainsString('settings[media.image_sizes]', $general);

        $security = $this->kernel()->handle(Request::create('GET', '/admin/settings/security'))->body();
        $this->assertStringContainsString('name="settings[security.two_factor_required_roles][]"', $security);
        $this->assertStringContainsString('name="settings[security.two_factor_enabled]"', $security);

        $this->assertSame(404, $this->kernel()->handle(Request::create('GET', '/admin/settings/unknown'))->status());
    }

    public function testSavingValidatesEverythingFirstAndKeepsTheSubmittedValues(): void
    {
        $bad = $this->kernel()->handle($this->post('/admin/settings/security', ['settings' => [
            'security.password_min_length' => '3',
            'security.session_lifetime' => '120',
        ]]));
        $this->assertSame(302, $bad->status());

        $settings = $this->settings();
        $this->assertSame(10, $settings->getInt('security.password_min_length', 10));
        $this->assertSame(1440, $settings->getInt('security.session_lifetime', 1440), 'nothing is saved when one field fails');

        $page = $this->kernel()->handle(Request::create('GET', '/admin/settings/security'))->body();
        $this->assertStringContainsString('data-prime-settings-errors', $page);
        $this->assertMatchesRegularExpression('/name="settings\[security\.password_min_length\]"\s+value="3"/', $page);

        $ok = $this->kernel()->handle($this->post('/admin/settings/security', ['settings' => [
            'security.password_min_length' => '12',
            'security.two_factor_enabled' => '1',
            'security.two_factor_required_roles' => ['', 'administrator'],
        ]]));
        $this->assertSame(302, $ok->status());
        $this->assertSame(12, $this->settings()->getInt('security.password_min_length', 10));
        $this->assertTrue($this->settings()->getBool('security.two_factor_enabled', false));
    }

    public function testSecretsAreEncryptedNeverEchoedAndKeptWhenLeftEmpty(): void
    {
        $this->kernel()->handle($this->post('/admin/settings/emails', ['settings' => [
            'mail.driver' => 'smtp',
            'mail.from_address' => 'site@example.test',
            'mail.host' => 'smtp.example.test',
            'mail.password' => 'S3cret-Pass!',
        ]]));

        $settings = $this->settings();
        $this->assertSame('S3cret-Pass!', $settings->getSecret('mail.password'));
        $row = $this->app->get(Connection::class)->selectOne("SELECT value FROM pc_settings WHERE name = 'mail.password'");
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('S3cret-Pass!', (string) $row['value']);

        $page = $this->kernel()->handle(Request::create('GET', '/admin/settings/emails'))->body();
        $this->assertStringNotContainsString('S3cret-Pass!', $page);
        $this->assertStringContainsString('Défini', $page);

        // An empty password field keeps the stored secret.
        $this->kernel()->handle($this->post('/admin/settings/emails', ['settings' => ['mail.password' => '', 'mail.from_address' => 'site@example.test']]));
        $this->assertSame('S3cret-Pass!', $this->settings()->getSecret('mail.password'));
    }

    public function testTestEmailIsLoggedAndCanBeResentButRedactedOnesCannot(): void
    {
        $sent = $this->kernel()->handle($this->post('/admin/settings/emails/test', ['to' => 'camille@prime-cms.local']));
        $this->assertSame(302, $sent->status());

        $page = $this->kernel()->handle(Request::create('GET', '/admin/settings/emails'))->body();
        $this->assertStringContainsString('camille@prime-cms.local', $page);
        $this->assertMatchesRegularExpression('#/admin/settings/emails/log/\d+/resend#', $page);

        $this->kernel()->handle($this->post('/admin/settings/emails/log/1/resend'));
        $this->assertSame(2, (int) ($this->app->get(Connection::class)->selectOne('SELECT COUNT(*) AS c FROM pc_mail_log')['c'] ?? 0));

        $invalid = $this->kernel()->handle($this->post('/admin/settings/emails/test', ['to' => 'not-an-email']));
        $this->assertSame(302, $invalid->status());
        $this->assertSame(2, (int) ($this->app->get(Connection::class)->selectOne('SELECT COUNT(*) AS c FROM pc_mail_log')['c'] ?? 0));

        $redacted = Mailer::redact(['name' => 'Camille', 'reset_url' => 'https://x.test/reset?token=abc']);
        $this->assertSame('[masqué]', $redacted['reset_url']);
        $this->assertTrue($redacted['redacted']);
        $this->assertFalse(Mailer::canResend(['template' => 'auth/reset', 'metadata' => ['data' => $redacted]]));
        $this->assertTrue(Mailer::canResend(['template' => 'test', 'metadata' => ['data' => []]]));
        $this->assertFalse(Mailer::canResend(['template' => null, 'metadata' => []]));

        $this->assertSame(404, $this->kernel()->handle($this->post('/admin/settings/emails/log/999/resend'))->status());
    }

    public function testSchedulerScreenListsCoreTasksAndRunsOneOnDemand(): void
    {
        $page = $this->kernel()->handle(Request::create('GET', '/admin/tools/scheduler'))->body();
        $this->assertStringContainsString('jobs.prune', $page);
        $this->assertStringContainsString('/admin/tools/scheduler/jobs.prune/run', $page);
        $this->assertStringContainsString('schedule:run', $page, 'the cron line is offered while cron never ran');

        $run = $this->kernel()->handle($this->post('/admin/tools/scheduler/jobs.prune/run'));
        $this->assertSame(302, $run->status());
        $this->assertSame(404, $this->kernel()->handle($this->post('/admin/tools/scheduler/nope/run'))->status());
    }

    public function testQueueScreenRetriesAndDeletesFailedJobs(): void
    {
        /** @var JobRepository $jobs */
        $jobs = $this->app->get(JobRepository::class);
        $id = $jobs->push('mail.send', ['to' => 'x@example.test']);
        $jobs->markFailed($id, 'SMTP 550');
        $other = $jobs->push('mail.send', ['to' => 'y@example.test']);

        $page = $this->kernel()->handle(Request::create('GET', '/admin/tools/queue'))->body();
        $this->assertStringContainsString('SMTP 550', $page);
        $this->assertStringContainsString('/admin/tools/queue/' . $id . '/retry', $page);
        $this->assertStringContainsString('/admin/tools/queue/' . $other . '/delete', $page);

        $this->kernel()->handle($this->post('/admin/tools/queue/' . $id . '/retry'));
        $this->assertSame(0, $jobs->stats()['failed']);

        $this->kernel()->handle($this->post('/admin/tools/queue/' . $other . '/delete'));
        $this->assertCount(1, $jobs->pending());
    }

    public function testSystemStatusReportHasNoSecretsAndPluginsCanAddChecks(): void
    {
        /** @var Hooks $hooks */
        $hooks = $this->app->get(Hooks::class);
        $hooks->filter('system.checks', static function (array $checks): array {
            $checks[] = ['id' => 'shop.gateway', 'group' => 'plugins', 'label' => 'Passerelle', 'status' => 'ok', 'value' => 'https://user:hunter2@api.example.test'];
            $checks[] = 'garbage';

            return $checks;
        });

        $page = $this->kernel()->handle(Request::create('GET', '/admin/tools/system'))->body();
        $this->assertStringContainsString('data-prime-check="php.version"', $page);
        $this->assertStringContainsString('data-prime-check="shop.gateway"', $page);

        /** @var SystemStatus $status */
        $status = $this->app->get(SystemStatus::class);
        $report = $status->report();
        $this->assertStringContainsString('Passerelle', $report);
        $this->assertStringNotContainsString('hunter2', $report);
        $this->assertStringNotContainsString(base64_encode(str_repeat('k', 32)), $report);
        $this->assertSame('password=***', SystemStatus::scrub('password=abc'));
    }

    public function testMaintenanceModeBlocksVisitorsButNotAdministrators(): void
    {
        $this->settings()->set('advanced.maintenance', true);
        $this->settings()->set('advanced.maintenance_message', 'Retour à 14 h.');

        $admin = $this->kernel()->handle(Request::create('GET', '/'));
        $this->assertNotSame(503, $admin->status());

        $this->app->get(Auth::class)->logout();
        $visitor = $this->kernel()->handle(Request::create('GET', '/'));
        $this->assertSame(503, $visitor->status());
        $this->assertStringContainsString('Retour à 14 h.', $visitor->body());
        $this->assertSame('600', $visitor->header('Retry-After'));
    }

    public function testToolsAreReservedToSettingsManagers(): void
    {
        $this->app->get(Auth::class)->logout();
        $response = $this->kernel()->handle(Request::create('GET', '/admin/tools/system'));
        $this->assertSame(302, $response->status());
        $this->assertStringStartsWith('/admin/login', (string) $response->header('Location'));
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = $this->app->get(Settings::class);
        $settings->flushCache();

        return $settings;
    }

    private function post(string $path, array $body = []): Request
    {
        return Request::create('POST', $path, body: $body + [
            \Prime\Security\Csrf::FIELD_NAME => $this->app->get(\Prime\Security\Csrf::class)->token(),
        ]);
    }

    private function kernel(): Kernel
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->get(Kernel::class);

        return $kernel;
    }

    private function createApp(): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        // A dedicated page-cache directory keeps this test independent from the other suites.
        $app->config()->set('cache.page.directory', $this->pageCacheDir);
        $app->config()->set('mail.driver', 'log');
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        // Settings are written with MySQL's upsert: the emulating PDO rewrites it for SQLite.
        $pdo = new MysqlEmulatingPdo('name', 'sqlite:' . $this->databaseFile);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, username VARCHAR(80) NULL UNIQUE, bio TEXT NULL, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) NOT NULL UNIQUE, value LONGTEXT NULL, autoload INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_mail_log (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient VARCHAR(255), subject VARCHAR(255), status VARCHAR(20), transport VARCHAR(32), template VARCHAR(120) NULL, metadata_json TEXT NULL, error_message TEXT NULL, attempts INTEGER DEFAULT 0, job_id INTEGER NULL, sent_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec("CREATE TABLE pc_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(64) DEFAULT 'default', handler VARCHAR(191), payload TEXT, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, available_at DATETIME, reserved_at DATETIME NULL, failed_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME)");

        $now = '2026-11-16 12:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, description, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 'Accès complet', 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*')");
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute(['camille@prime-cms.local', password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT), 'Camille Admin', 'active', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1)');

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $user = $users->find(1);
        if ($user !== null) {
            $auth->login($user);
        }

        return $app;
    }
}
