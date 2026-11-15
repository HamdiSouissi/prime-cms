<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\Auth;
use Prime\Auth\AuthResult;
use Prime\Auth\Capabilities;
use Prime\Auth\Exception\PolicyException;
use Prime\Auth\PasswordResetService;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Database\Migrations\Migrator;
use Prime\Kernel\Application;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\Security\Encrypter;
use Prime\Security\RateLimiter;
use Prime\Security\Totp;

/**
 * Real MySQL 8.0 / MariaDB 10.6 integration tests for Phase 4 migrations,
 * foreign-key cascades, seeded roles, Auth, RBAC, 2FA, rate limiting, and PasswordResetService.
 */
#[Group('database')]
final class AuthDatabaseIntegrationTest extends DatabaseTestCase
{
    public function testMigrationsSeedSystemRolesAndCascadeDeletesOnMysqlAndMariaDb(): void
    {
        $migrator = new Migrator($this->connection, dirname(__DIR__, 2) . '/database/migrations');
        $ran = $migrator->migrate();

        $this->assertCount(6, $ran);

        $capabilities = new Capabilities();
        $rolesRepo = new RoleRepository($this->connection, $capabilities);
        $usersRepo = new UserRepository($this->connection, $rolesRepo);

        // Verify 4 seeded system roles
        $allRoles = $rolesRepo->all();
        $this->assertCount(4, $allRoles);

        $adminRole = $rolesRepo->findBySlug('administrator');
        $editorRole = $rolesRepo->findBySlug('editor');
        $authorRole = $rolesRepo->findBySlug('author');
        $contribRole = $rolesRepo->findBySlug('contributor');

        $this->assertNotNull($adminRole);
        $this->assertNotNull($editorRole);
        $this->assertNotNull($authorRole);
        $this->assertNotNull($contribRole);

        $this->assertTrue($adminRole->isSystem);
        $this->assertSame(['*'], $adminRole->capabilities);
        $this->assertContains('content.publish', $editorRole->capabilities);
        $this->assertNotContains('content.publish', $contribRole->capabilities);
        $this->assertContains('content.create', $contribRole->capabilities);

        // Create two users and verify foreign key cascades on delete
        $admin = $usersRepo->create(
            email: 'admin@mysql-it.test',
            displayName: 'Admin MySQL',
            plainPassword: 'Saffron!Horizon#8429',
            roleIds: [$adminRole->id],
        );

        $editor = $usersRepo->create(
            email: 'editor@mysql-it.test',
            displayName: 'Editor MySQL',
            plainPassword: 'Emerald!Compass#7291',
            roleIds: [$editorRole->id],
        );

        // Insert remember_token and password_reset rows linked to editor
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->connection->table('remember_tokens')->insert([
            'user_id' => $editor->id,
            'selector' => bin2hex(random_bytes(12)),
            'validator_hash' => hash('sha256', 'v'),
            'expires_at' => $future,
            'created_at' => $now,
        ]);
        $this->connection->table('password_resets')->insert([
            'user_id' => $editor->id,
            'token_hash' => hash('sha256', 't'),
            'expires_at' => $future,
            'created_at' => $now,
        ]);

        // Deleting editor cascades cleanly to user_roles, remember_tokens, and password_resets!
        $usersRepo->delete($editor->id);
        $this->assertNull($usersRepo->find($editor->id));
        $this->assertSame(0, $this->connection->table('user_roles')->where('user_id', '=', $editor->id)->count());
        $this->assertSame(0, $this->connection->table('remember_tokens')->where('user_id', '=', $editor->id)->count());
        $this->assertSame(0, $this->connection->table('password_resets')->where('user_id', '=', $editor->id)->count());

        // Last active administrator invariant enforced on live MySQL/MariaDB
        $guard = new UserGuard($usersRepo, $rolesRepo);
        $this->assertSame(1, $usersRepo->countActiveAdministrators());

        $blocked = false;
        try {
            $guard->assertCanDisable(null, $admin);
        } catch (PolicyException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'Disabling the sole active administrator must throw PolicyException.');

        // Full rollback of all 6 migrations must succeed cleanly without FK errors
        $rolledBack = $migrator->reset();
        $this->assertCount(6, $rolledBack);
    }

    public function testEndToEndAuthTwoFactorRateLimiterAndPasswordResetOnRealDatabase(): void
    {
        $migrator = new Migrator($this->connection, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->migrate();

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'production');
        $app->config()->set('app.debug', false);
        $app->config()->set('app.url', 'https://prime-db.example');
        $app->config()->set('app.key', Encrypter::generateKey());
        $app->boot();

        $app->container()->instance(\Prime\Database\Connection::class, $this->connection);

        $sent = [];
        $app->container()->instance(MailerInterface::class, new class ($sent) implements MailerInterface {
            /** @param list<Message> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function send(Message $message): void
            {
                $this->sent[] = $message;
            }
        });

        /** @var RoleRepository $rolesRepo */
        $rolesRepo = $app->get(RoleRepository::class);
        /** @var UserRepository $usersRepo */
        $usersRepo = $app->get(UserRepository::class);
        /** @var Auth $auth */
        $auth = $app->get(Auth::class);
        /** @var TwoFactorService $twoFactor */
        $twoFactor = $app->get(TwoFactorService::class);
        /** @var PasswordResetService $passwordResets */
        $passwordResets = $app->get(PasswordResetService::class);
        /** @var RateLimiter $rateLimiter */
        $rateLimiter = $app->get(RateLimiter::class);

        $adminRole = $rolesRepo->findBySlug('administrator');
        $this->assertNotNull($adminRole);

        $user = $usersRepo->create(
            email: 'camille@mysql-it.test',
            displayName: 'Camille DB',
            plainPassword: 'Saffron!Horizon#8429',
            roleIds: [$adminRole->id],
        );

        // 1. Login succeeds and sets last_login_at
        $loginResult = $auth->attempt('camille@mysql-it.test', 'Saffron!Horizon#8429', remember: true, ip: '203.0.113.10');
        $this->assertSame(AuthResult::STATUS_AUTHENTICATED, $loginResult->status);
        $this->assertNotNull($usersRepo->find($user->id)?->lastLoginAt);

        // 2. Enable 2FA (AES-256-GCM encrypted secret stored in MySQL/MariaDB)
        $setup = $twoFactor->generateSetupSecret($user);
        $recovery = $twoFactor->confirmAndEnable($user, $setup['secret'], Totp::code($setup['secret'], time()));
        $this->assertNotNull($recovery);
        $this->assertCount(8, $recovery);

        $rawRow = $usersRepo->findRawById($user->id);
        $this->assertIsArray($rawRow);
        $this->assertNotSame($setup['secret'], (string) $rawRow['two_factor_secret']);

        // Logout -> login requires 2FA -> complete with recovery code
        $auth->logout();
        $step1 = $auth->attempt('camille@mysql-it.test', 'Saffron!Horizon#8429', ip: '203.0.113.10');
        $this->assertSame(AuthResult::STATUS_TWO_FACTOR_REQUIRED, $step1->status);

        $step2 = $auth->completeTwoFactor($recovery[0], ip: '203.0.113.10');
        $this->assertSame(AuthResult::STATUS_AUTHENTICATED, $step2->status);
        $this->assertSame(7, $twoFactor->remainingRecoveryCodesCount($user->id));

        // 3. Password reset persists hashed token and rotates password
        $passwordResets->requestReset('camille@mysql-it.test', '203.0.113.10');
        $this->assertCount(1, $sent);
        $this->assertSame(1, preg_match('#/admin/reset-password/([a-f0-9]{64})#', $sent[0]->text, $m));

        $resetUser = $passwordResets->resetPassword($m[1], 'Cerulean!Summit#4917');
        $this->assertNotNull($resetUser);
        $this->assertSame($user->id, $resetUser->id);

        // 4. Database-backed RateLimiter increments & resets cleanly on MySQL/MariaDB
        $key = 'mysql-it-bucket';
        $this->assertFalse($rateLimiter->tooManyAttempts($key, 2));
        $rateLimiter->hit($key, 60);
        $rateLimiter->hit($key, 60);
        $this->assertTrue($rateLimiter->tooManyAttempts($key, 2));
        $rateLimiter->clear($key);
        $this->assertFalse($rateLimiter->tooManyAttempts($key, 2));
    }
}
