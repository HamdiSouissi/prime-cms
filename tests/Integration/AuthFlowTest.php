<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\AuthResult;
use Prime\Auth\TwoFactorPolicy;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\Security\Csrf;
use Prime\Security\Totp;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class AuthFlowTest extends TestCase
{
    /** @var list<Message> */
    private array $sentMessages = [];

    public function testLoginSuccessFailureAndOpenRedirectProtection(): void
    {
        $app = $this->createApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $events = [];
        $hooks->on('auth.failed', static function () use (&$events): void {
            $events[] = 'failed';
        });
        $hooks->on('auth.login', static function () use (&$events): void {
            $events[] = 'login';
        });

        // 1. Wrong password & unknown email produce identical 422 response with generic French error
        $wrongPwd = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
            'password' => 'WrongPassword#9999',
        ]));
        $this->assertSame(422, $wrongPwd->status());
        $this->assertStringContainsString(Auth::GENERIC_LOGIN_ERROR, $wrongPwd->body());

        $unknownEmail = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'does-not-exist@prime-cms.local',
            'password' => 'WrongPassword#9999',
        ]));
        $this->assertSame(422, $unknownEmail->status());
        $this->assertStringContainsString(Auth::GENERIC_LOGIN_ERROR, $unknownEmail->body());
        $this->assertSame(['failed', 'failed'], $events);

        // 2. Disabled user gets the exact same generic error (anti-enumeration)
        $disabledRes = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'disabled@prime-cms.local',
            'password' => 'Saffron!Horizon#8429',
        ]));
        $this->assertSame(422, $disabledRes->status());
        $this->assertStringContainsString(Auth::GENERIC_LOGIN_ERROR, $disabledRes->body());

        // 3. Open-redirect attempt in session intended URL is neutralized to /admin
        /** @var Session $session */
        $session = $app->get(Session::class);
        $session->set('_auth.intended', 'https://evil.example/phish');

        $okRes = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
            'password' => 'Saffron!Horizon#8429',
        ]));
        $this->assertSame(302, $okRes->status());
        $this->assertSame('/admin', $okRes->header('Location'));
        $this->assertContains('login', $events);

        // Authenticated user now accesses /admin and /admin/login redirects to /admin
        $this->assertSame(200, $kernel->handle(Request::create('GET', '/admin'))->status());
        $loginWhenAuthed = $kernel->handle(Request::create('GET', '/admin/login'));
        $this->assertSame(302, $loginWhenAuthed->status());
        $this->assertSame('/admin', $loginWhenAuthed->header('Location'));

        // 4. GET /admin/logout returns 405; POST /admin/logout logs out cleanly
        $this->assertSame(405, $kernel->handle(Request::create('GET', '/admin/logout'))->status());
        $logoutRes = $kernel->handle(Request::create('POST', '/admin/logout', body: [
            '_csrf_token' => $this->csrfToken($app),
        ]));
        $this->assertSame(302, $logoutRes->status());
        $this->assertSame('/admin/login', $logoutRes->header('Location'));
        $this->assertSame(302, $kernel->handle(Request::create('GET', '/admin'))->status());
    }

    public function testLoginRateLimitingReturns429AfterFiveFailures(): void
    {
        $app = $this->createApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);

        $locked = false;
        $hooks->on('auth.lockout', static function () use (&$locked): void {
            $locked = true;
        });

        for ($i = 0; $i < 5; $i++) {
            $res = $kernel->handle(Request::create('POST', '/admin/login', body: [
                '_csrf_token' => $this->csrfToken($app),
                'email' => 'camille@prime-cms.local',
                'password' => 'BadPass!' . $i . '12345',
            ], server: ['REMOTE_ADDR' => '198.51.100.42']));
            $this->assertSame(422, $res->status());
        }

        // 6th attempt triggers lockout (429 Too Many Requests + Retry-After)
        $sixth = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
            'password' => 'Saffron!Horizon#8429',
        ], server: ['REMOTE_ADDR' => '198.51.100.42']));

        $this->assertSame(429, $sixth->status());
        $this->assertNotNull($sixth->header('Retry-After'));
        $this->assertTrue($locked);
    }

    public function testRememberMeRotationAndTheftDetection(): void
    {
        $app = $this->createApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var Session $session */
        $session = $app->get(Session::class);

        $loginRes = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
            'password' => 'Saffron!Horizon#8429',
            'remember' => '1',
        ]));
        $this->assertSame(302, $loginRes->status());

        $cookie = $loginRes->cookie($auth->rememberCookieName());
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie['httpOnly']);
        $this->assertSame('Lax', $cookie['sameSite']);
        $originalToken = $cookie['value'];
        $this->assertStringContainsString(':', $originalToken);

        // Simulate expired PHP session, returning with remember cookie -> auto-authenticates and ROTATES token
        $session->clear();
        $auth->resetCache();

        $autoRes = $kernel->handle(Request::create('GET', '/admin', cookies: [
            $auth->rememberCookieName() => $originalToken,
        ]));
        $this->assertSame(200, $autoRes->status());
        $rotatedCookie = $autoRes->cookie($auth->rememberCookieName());
        $this->assertNotNull($rotatedCookie);
        $this->assertNotSame($originalToken, $rotatedCookie['value']);

        // Replay of OLD (stolen) token triggers theft detection -> rejected AND revokes all tokens for user
        $session->clear();
        $auth->resetCache();
        $theftRes = $kernel->handle(Request::create('GET', '/admin', cookies: [
            $auth->rememberCookieName() => $originalToken,
        ]));
        $this->assertSame(302, $theftRes->status());
        $this->assertSame('/admin/login', $theftRes->header('Location'));

        // Even the rotated token is now revoked!
        $session->clear();
        $auth->resetCache();
        $afterTheft = $kernel->handle(Request::create('GET', '/admin', cookies: [
            $auth->rememberCookieName() => $rotatedCookie['value'],
        ]));
        $this->assertSame(302, $afterTheft->status());
    }

    public function testSessionTimeoutsAndSessionVersionInvalidation(): void
    {
        $app = $this->createApp();
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var Session $session */
        $session = $app->get(Session::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);

        $user = $users->find(1);
        $this->assertNotNull($user);
        $auth->login($user);
        $this->assertTrue($auth->check());

        // Bumping session_version invalidates existing sessions immediately
        $users->incrementSessionVersionAndRevokeTokens($user->id);
        $auth->resetCache();
        $this->assertNull($auth->user());

        // Re-login and simulate idle timeout (> 2h)
        $freshUser = $users->find(1);
        $this->assertNotNull($freshUser);
        $auth->login($freshUser);
        $session->set('_auth.last_activity_at', time() - 7500);
        $auth->resetCache();
        $this->assertNull($auth->user());

        // Re-login and simulate absolute timeout (> 12h)
        $auth->login($freshUser);
        $session->set('_auth.logged_in_at', time() - 45000);
        $auth->resetCache();
        $this->assertNull($auth->user());
    }

    public function testPasswordResetFullLifecycleAndSingleUseToken(): void
    {
        $app = $this->createApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);

        // Request reset for unknown email -> identical 200 anti-enumeration message, no mail sent
        $unknown = $kernel->handle(Request::create('POST', '/admin/forgot-password', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'nobody@prime-cms.local',
        ]));
        $this->assertSame(200, $unknown->status());
        $this->assertCount(0, $this->sentMessages);

        // Request reset for valid email -> identical 200 message AND mail sent with 64-hex token
        $known = $kernel->handle(Request::create('POST', '/admin/forgot-password', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
        ]));
        $this->assertSame(200, $known->status());
        $this->assertCount(1, $this->sentMessages);

        $mailText = $this->sentMessages[0]->text;
        $this->assertSame(1, preg_match('#/admin/reset-password/([a-f0-9]{64})#', $mailText, $m));
        $token = $m[1];

        // GET reset form succeeds
        $formRes = $kernel->handle(Request::create('GET', '/admin/reset-password/' . $token));
        $this->assertSame(200, $formRes->status());
        $this->assertStringContainsString('Nouveau mot de passe', $formRes->body());

        // POST reset form updates password and sends confirmation email
        $resetRes = $kernel->handle(Request::create('POST', '/admin/reset-password/' . $token, body: [
            '_csrf_token' => $this->csrfToken($app),
            'password' => 'Cerulean!Summit#4917',
            'password_confirmation' => 'Cerulean!Summit#4917',
        ]));
        $this->assertSame(302, $resetRes->status());
        $this->assertSame('/admin/login', $resetRes->header('Location'));
        $this->assertCount(2, $this->sentMessages); // reset + password changed notification

        // Reusing token shows expired page (410 Gone or 200 with expired message)
        $reuse = $kernel->handle(Request::create('GET', '/admin/reset-password/' . $token));
        $this->assertContains($reuse->status(), [200, 410]);
        $this->assertStringContainsString('expiré', strtolower($reuse->body()));

        // Login with NEW password works; OLD password fails
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        $this->assertSame(
            AuthResult::STATUS_FAILED,
            $auth->attempt('camille@prime-cms.local', 'Saffron!Horizon#8429')->status,
        );
        $this->assertSame(
            AuthResult::STATUS_AUTHENTICATED,
            $auth->attempt('camille@prime-cms.local', 'Cerulean!Summit#4917')->status,
        );

        // Ensure session_version bumped
        $updated = $users->find(1);
        $this->assertNotNull($updated);
        $this->assertGreaterThan(1, $updated->sessionVersion);
    }

    public function testTwoFactorEnrollmentChallengeAntiReplayAndRecoveryCodes(): void
    {
        $app = $this->createApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var TwoFactorService $twoFactor */
        $twoFactor = $app->get(TwoFactorService::class);
        /** @var Session $session */
        $session = $app->get(Session::class);

        $user = $users->find(1);
        $this->assertNotNull($user);
        $auth->login($user);

        // Start 2FA setup on profile
        $startRes = $kernel->handle(Request::create('POST', '/admin/profile/2fa/start', body: [
            '_csrf_token' => $this->csrfToken($app),
        ]));
        $this->assertSame(302, $startRes->status());

        $pending = $session->get('_profile.2fa_pending_secret');
        $this->assertIsArray($pending);
        $secret = (string) $pending['secret'];

        // Confirm with valid TOTP code
        $validCode = Totp::code($secret, time());
        $confirmRes = $kernel->handle(Request::create('POST', '/admin/profile/2fa/confirm', body: [
            '_csrf_token' => $this->csrfToken($app),
            'code' => $validCode,
        ]));
        $this->assertSame(302, $confirmRes->status());

        $recoveryCodes = $session->getFlash('_profile.recovery_codes_once');
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
        $this->assertSame(8, $twoFactor->remainingRecoveryCodesCount($user->id));

        // Logout -> login enters 2FA pending challenge
        $auth->logout();
        $step1 = $kernel->handle(Request::create('POST', '/admin/login', body: [
            '_csrf_token' => $this->csrfToken($app),
            'email' => 'camille@prime-cms.local',
            'password' => 'Saffron!Horizon#8429',
        ]));
        $this->assertSame(302, $step1->status());
        $this->assertSame('/admin/login/two-factor', $step1->header('Location'));
        $this->assertTrue($auth->guest());
        $this->assertTrue($auth->hasPendingTwoFactor());

        // Replaying the EXACT same TOTP code used during confirmation (same 30s time step) MUST be rejected!
        $replay = $kernel->handle(Request::create('POST', '/admin/login/two-factor', body: [
            '_csrf_token' => $this->csrfToken($app),
            'code' => $validCode,
        ]));
        $this->assertSame(422, $replay->status());
        $this->assertTrue($auth->guest());

        // Submitting one single-use recovery code succeeds and burns it (8 -> 7)
        $firstRecovery = (string) $recoveryCodes[0];
        $step2 = $kernel->handle(Request::create('POST', '/admin/login/two-factor', body: [
            '_csrf_token' => $this->csrfToken($app),
            'code' => $firstRecovery,
        ]));
        $this->assertSame(302, $step2->status());
        $this->assertSame('/admin', $step2->header('Location'));
        $this->assertTrue($auth->check());
        $this->assertSame(7, $twoFactor->remainingRecoveryCodesCount($user->id));

        // Disable 2FA with password + second recovery code
        $disableRes = $kernel->handle(Request::create('POST', '/admin/profile/2fa/disable', body: [
            '_csrf_token' => $this->csrfToken($app),
            'current_password' => 'Saffron!Horizon#8429',
            'code' => (string) $recoveryCodes[1],
        ]));
        $this->assertSame(302, $disableRes->status());
        $reloaded = $users->find(1);
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->hasTwoFactor());
    }

    public function testMandatoryTwoFactorHoldsTheAccountOnTheEnrollmentScreenUntilItIsActivated(): void
    {
        $app = $this->createApp();

        $settingsPdo = new MysqlEmulatingPdo('name');
        $settingsPdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );
        $settings = new Settings(new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $settingsPdo));
        $settings->set(TwoFactorPolicy::SETTING_ENABLED, true);
        $settings->set(TwoFactorPolicy::SETTING_ROLES, ['administrator']);
        $app->container()->instance(TwoFactorPolicy::class, new TwoFactorPolicy($settings));

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var UserRepository $users */
        $users = $app->get(UserRepository::class);
        /** @var Session $session */
        $session = $app->get(Session::class);

        $user = $users->find(1);
        $this->assertNotNull($user);
        $auth->login($user);

        // Any admin screen sends the account to the enrollment step of its profile.
        $dashboard = $kernel->handle(Request::create('GET', '/admin'));
        $this->assertSame(302, $dashboard->status());
        $this->assertSame(TwoFactorPolicy::ENROLLMENT_URL, $dashboard->header('Location'));

        // JSON callers get a 403 carrying the redirect instead of an HTML bounce.
        $json = $kernel->handle(Request::create('GET', '/admin/content/post', headers: ['Accept' => 'application/json']));
        $this->assertSame(403, $json->status());
        $this->assertStringContainsString('two-factor', $json->body());

        // The profile itself stays reachable and shows the QR code once setup has started.
        $profile = $kernel->handle(Request::create('GET', '/admin/profile', query: ['enroll' => 'two-factor']));
        $this->assertSame(200, $profile->status());
        $this->assertStringContainsString('data-prime-two-factor-required', $profile->body());

        $start = $kernel->handle(Request::create('POST', '/admin/profile/2fa/start', body: [
            '_csrf_token' => $this->csrfToken($app),
        ]));
        $this->assertSame(302, $start->status());
        $this->assertStringStartsWith('/admin/profile', (string) $start->header('Location'));

        $withQr = $kernel->handle(Request::create('GET', '/admin/profile', query: ['tab' => 'security']));
        $this->assertSame(200, $withQr->status());
        $this->assertStringContainsString('data-prime-totp-qr', $withQr->body());
        $this->assertMatchesRegularExpression('/<svg[^>]+role="img"[^>]+aria-label="[^"]+"/', $withQr->body());

        $pending = $session->get('_profile.2fa_pending_secret');
        $this->assertIsArray($pending);
        $confirm = $kernel->handle(Request::create('POST', '/admin/profile/2fa/confirm', body: [
            '_csrf_token' => $this->csrfToken($app),
            'code' => Totp::code((string) $pending['secret'], time()),
        ]));
        $this->assertSame(302, $confirm->status());

        // Once enrolled, the rest of the admin opens up again.
        $after = $kernel->handle(Request::create('GET', '/admin'));
        $this->assertNotSame(TwoFactorPolicy::ENROLLMENT_URL, $after->header('Location'));
    }

    private function createApp(): Application
    {
        $this->sentMessages = [];

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'local');
        $app->config()->set('app.debug', true);
        $app->config()->set('app.url', 'https://prime.example');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('z', 32)));
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
        $pdo->exec("INSERT INTO pc_role_capabilities VALUES (1, '*')");

        $hash = password_hash('Saffron!Horizon#8429', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO pc_users (id, email, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([1, 'camille@prime-cms.local', $hash, 'Camille Admin', 'active', 'fr', $now, $now]);
        $stmt->execute([2, 'disabled@prime-cms.local', $hash, 'Compte Désactivé', 'disabled', 'fr', $now, $now]);
        $pdo->exec('INSERT INTO pc_user_roles VALUES (1, 1), (2, 1)');

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        return $app;
    }

    private function csrfToken(Application $app): string
    {
        /** @var Csrf $csrf */
        $csrf = $app->get(Csrf::class);

        return $csrf->token();
    }
}
