<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Auth\Auth;
use Prime\Auth\AuthResult;
use Prime\Auth\Exception\PolicyException;
use Prime\Auth\PasswordResetService;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserGuard;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Kernel\Application;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\Security\Hasher;
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
        $migrator = new Migrator(
            $this->connection,
            new Schema($this->connection),
            [dirname(__DIR__, 2) . '/database/migrations'],
        );
        $ran = $migrator->run();

        $this->assertCount($this->coreMigrationCount(), $ran);

        $rolesRepo = new RoleRepository($this->connection);
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

        // Full rollback of all core migrations must succeed cleanly without FK errors
        $rolledBack = $migrator->reset();
        $this->assertCount($this->coreMigrationCount(), $rolledBack);
    }

    public function testEndToEndAuthTwoFactorRateLimiterAndPasswordResetOnRealDatabase(): void
    {
        $migrator = new Migrator(
            $this->connection,
            new Schema($this->connection),
            [dirname(__DIR__, 2) . '/database/migrations'],
        );
        $migrator->run();

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'production');
        $app->config()->set('app.debug', false);
        $app->config()->set('app.url', 'https://prime-db.example');
        $app->config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app->boot();

        $app->container()->instance(Connection::class, $this->connection);

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
        $passwordResets->requestPublicReset(
            'camille@mysql-it.test',
            '203.0.113.10',
            'https://prime-db.example',
            'Prime DB',
        );
        $this->assertCount(1, $sent);
        $this->assertSame(1, preg_match('#/admin/reset-password/([a-f0-9]{64})#', $sent[0]->text, $m));

        $resetUser = $passwordResets->resetPassword($m[1], 'Cerulean!Summit#4917', 'Prime DB');
        $this->assertSame($user->id, $resetUser->id);

        // 4. Database-backed RateLimiter increments & resets cleanly on MySQL/MariaDB
        $key = 'mysql-it-bucket';
        $this->assertFalse($rateLimiter->tooManyAttempts($key, 2));
        $rateLimiter->hit($key, 60);
        $rateLimiter->hit($key, 60);
        $this->assertTrue($rateLimiter->tooManyAttempts($key, 2));
        $rateLimiter->clear($key);
        $this->assertFalse($rateLimiter->tooManyAttempts($key, 2));

        // 5. Timing enumeration protection with real Hasher (Argon2id in CI) on live MySQL/MariaDB
        /** @var Hasher $hasher */
        $hasher = $app->get(Hasher::class);
        $realInfo = password_get_info($hasher->make('Cerulean!Summit#4917'));
        $dummyInfo = password_get_info($hasher->dummyHash());
        $this->assertSame($realInfo['algoName'], $dummyInfo['algoName']);
        $this->assertSame($realInfo['options'], $dummyInfo['options']);

        $disabledUser = $usersRepo->create(
            email: 'disabled@mysql-it.test',
            displayName: 'Disabled DB',
            plainPassword: 'Violet!Meadow#5182',
            roleIds: [$adminRole->id],
            status: 'disabled',
        );
        $this->assertFalse($disabledUser->isActive());

        // Warm-up dummy hash & PDO statement caches
        $auth->attempt('warmup@mysql-it.test', 'Wrong!Password#1111', ip: '198.51.100.1');
        $auth->attempt('camille@mysql-it.test', 'Wrong!Password#1111', ip: '198.51.100.2');

        $missingSamples = [];
        $existingSamples = [];
        $disabledSamples = [];

        for ($i = 0; $i < 5; $i++) {
            $t0 = hrtime(true);
            $rMissing = $auth->attempt('nobody-' . $i . '@mysql-it.test', 'Wrong!Password#1111', ip: '198.51.100.' . (10 + $i));
            $missingSamples[] = (hrtime(true) - $t0) / 1_000_000.0;
            $this->assertSame(AuthResult::STATUS_FAILED, $rMissing->status);

            $t0 = hrtime(true);
            $rExisting = $auth->attempt('camille@mysql-it.test', 'Wrong!Password#1111', ip: '198.51.100.' . (30 + $i));
            $existingSamples[] = (hrtime(true) - $t0) / 1_000_000.0;
            $this->assertSame(AuthResult::STATUS_FAILED, $rExisting->status);

            $t0 = hrtime(true);
            $rDisabled = $auth->attempt('disabled@mysql-it.test', 'Violet!Meadow#5182', ip: '198.51.100.' . (50 + $i));
            $disabledSamples[] = (hrtime(true) - $t0) / 1_000_000.0;
            $this->assertSame(AuthResult::STATUS_FAILED, $rDisabled->status);
        }

        sort($missingSamples);
        sort($existingSamples);
        sort($disabledSamples);
        $medianMissing = $missingSamples[2];
        $medianExisting = $existingSamples[2];
        $medianDisabled = $disabledSamples[2];

        $ratioMissing = $medianMissing / $medianExisting;
        $ratioDisabled = $medianDisabled / $medianExisting;

        // The dummy hash exists so a missing account never answers "for free"; the floor only has to
        // sit far above what an unguarded lookup would cost (~0.1) while staying wide enough that CPU
        // contention between two runners (PHP 8.2 vs 8.3, Argon2id tuning) cannot fail the suite.
        $this->assertGreaterThanOrEqual(
            0.3,
            $ratioMissing,
            sprintf('Missing vs existing ratio too low: %.3f (missing=%.2fms, existing=%.2fms)', $ratioMissing, $medianMissing, $medianExisting),
        );
        $this->assertLessThanOrEqual(
            1.6,
            $ratioMissing,
            sprintf('Missing vs existing ratio too high: %.3f (missing=%.2fms, existing=%.2fms)', $ratioMissing, $medianMissing, $medianExisting),
        );
        $this->assertGreaterThanOrEqual(
            0.6,
            $ratioDisabled,
            sprintf('Disabled vs existing ratio too low: %.3f (disabled=%.2fms, existing=%.2fms)', $ratioDisabled, $medianDisabled, $medianExisting),
        );
        $this->assertLessThanOrEqual(
            1.6,
            $ratioDisabled,
            sprintf('Disabled vs existing ratio too high: %.3f (disabled=%.2fms, existing=%.2fms)', $ratioDisabled, $medianDisabled, $medianExisting),
        );
    }
}
