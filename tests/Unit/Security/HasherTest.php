<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Auth;
use Prime\Auth\AuthResult;
use Prime\Auth\RoleRepository;
use Prime\Auth\TwoFactorService;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Session;
use Prime\Security\Csrf;
use Prime\Security\Encrypter;
use Prime\Security\Hasher;
use Prime\Security\RateLimiter;

final class HasherTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*') ?: [];
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testMakeVerifyAndNeedsRehashRespectConfigOptions(): void
    {
        $hasher = new Hasher(['hash_driver' => 'bcrypt', 'bcrypt' => ['cost' => 10]]);
        $hash = $hasher->make('Saffron!Horizon#8429');

        $this->assertTrue($hasher->verify('Saffron!Horizon#8429', $hash));
        $this->assertFalse($hasher->verify('WrongPassword#9999', $hash));
        $this->assertFalse($hasher->verify('Saffron!Horizon#8429', ''));
        $this->assertFalse($hasher->needsRehash($hash));

        // Stronger cost triggers transparent needsRehash()
        $strongerHasher = new Hasher(['hash_driver' => 'bcrypt', 'bcrypt' => ['cost' => 12]]);
        $this->assertTrue($strongerHasher->needsRehash($hash));
    }

    public function testDummyHashUsesExactSameAlgorithmAndOptionsAsMake(): void
    {
        $cacheDir = $this->createTempDir();
        $defaultConfig = require dirname(__DIR__, 3) . '/config/auth.php';
        $hasher = new Hasher($defaultConfig, $cacheDir);

        $realHash = $hasher->make('Saffron!Horizon#8429');
        $dummyHash = $hasher->dummyHash();

        $realInfo = password_get_info($realHash);
        $dummyInfo = password_get_info($dummyHash);

        $this->assertSame($realInfo['algo'], $dummyInfo['algo']);
        $this->assertSame($realInfo['algoName'], $dummyInfo['algoName']);
        $this->assertSame($realInfo['options'], $dummyInfo['options']);
        $this->assertFalse($hasher->needsRehash($dummyHash));

        // Second instance reusing the same cache directory loads the cached dummy hash verbatim
        $hasherFromCache = new Hasher($defaultConfig, $cacheDir);
        $this->assertSame($dummyHash, $hasherFromCache->dummyHash());

        // Changing options invalidates fingerprint and generates a fresh dummy hash matching new options
        $reconfigured = new Hasher(['hash_driver' => 'bcrypt', 'bcrypt' => ['cost' => 10]], $cacheDir);
        $newDummy = $reconfigured->dummyHash();
        $this->assertNotSame($dummyHash, $newDummy);
        $this->assertSame(
            password_get_info($reconfigured->make('Check#123456'))['options'],
            password_get_info($newDummy)['options'],
        );
    }

    public function testLoginTimingParityBetweenMissingExistingAndDisabledAccounts(): void
    {
        $cacheDir = $this->createTempDir();
        $defaultConfig = require dirname(__DIR__, 3) . '/config/auth.php';
        $hasher = new Hasher($defaultConfig, $cacheDir);

        // Warm up the cached dummy hash so steady-state request timings are measured fairly
        $hasher->dummyVerify('warmup-pass');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');

        $connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $roles = new RoleRepository($connection);
        $users = new UserRepository($connection, $roles, $hasher);
        $session = new Session($this->createTempDir());
        $csrf = new Csrf($session);
        $rateLimiter = new RateLimiter($connection);
        $encrypter = new Encrypter('base64:' . base64_encode(str_repeat('t', 32)));
        $twoFactor = new TwoFactorService($connection, $users, $encrypter, hasher: $hasher);

        // Allow enough attempts to measure 5 runs cleanly without lockout
        $authConfig = array_merge($defaultConfig, [
            'login_max_attempts' => 50,
            'login_ip_max_attempts' => 100,
        ]);

        $auth = new Auth(
            connection: $connection,
            users: $users,
            session: $session,
            csrf: $csrf,
            rateLimiter: $rateLimiter,
            twoFactor: $twoFactor,
            config: $authConfig,
            hasher: $hasher,
        );

        $users->create(
            email: 'existing@prime-cms.local',
            displayName: 'Existing User',
            plainPassword: 'Saffron!Horizon#8429',
            roleIds: [],
            status: 'active',
        );
        $users->create(
            email: 'disabled@prime-cms.local',
            displayName: 'Disabled User',
            plainPassword: 'Saffron!Horizon#8429',
            roleIds: [],
            status: 'disabled',
        );

        // Warm up the cached dummy hash so all 5 measured iterations compare verification parity directly
        $hasher->dummyHash();

        $missingTimes = [];
        $existingTimes = [];
        $disabledTimes = [];

        // Interleave attempts to cancel out any transient CPU jitter
        for ($i = 0; $i < 5; $i++) {
            $t0 = hrtime(true);
            $rMissing = $auth->attempt('missing-' . $i . '@prime-cms.local', 'WrongPassword#9999', '203.0.113.5');
            $missingTimes[] = (hrtime(true) - $t0) / 1e9;
            $this->assertSame(AuthResult::STATUS_FAILED, $rMissing->status);

            $t1 = hrtime(true);
            $rExisting = $auth->attempt('existing@prime-cms.local', 'WrongPassword#9999', '203.0.113.5');
            $existingTimes[] = (hrtime(true) - $t1) / 1e9;
            $this->assertSame(AuthResult::STATUS_FAILED, $rExisting->status);

            $t2 = hrtime(true);
            $rDisabled = $auth->attempt('disabled@prime-cms.local', 'Saffron!Horizon#8429', '203.0.113.5');
            $disabledTimes[] = (hrtime(true) - $t2) / 1e9;
            $this->assertSame(AuthResult::STATUS_FAILED, $rDisabled->status);
        }

        $medianMissing = self::median($missingTimes);
        $medianExisting = self::median($existingTimes);
        $medianDisabled = self::median($disabledTimes);

        $ratioMissingToExisting = $medianMissing / $medianExisting;
        $ratioDisabledToExisting = $medianDisabled / $medianExisting;

        $this->assertGreaterThanOrEqual(
            0.6,
            $ratioMissingToExisting,
            sprintf('Missing-email median (%.4fs) too fast vs existing-email median (%.4fs), ratio=%.2f', $medianMissing, $medianExisting, $ratioMissingToExisting),
        );
        $this->assertLessThanOrEqual(
            1.6,
            $ratioMissingToExisting,
            sprintf('Missing-email median (%.4fs) too slow vs existing-email median (%.4fs), ratio=%.2f', $medianMissing, $medianExisting, $ratioMissingToExisting),
        );

        $this->assertGreaterThanOrEqual(0.6, $ratioDisabledToExisting);
        $this->assertLessThanOrEqual(1.6, $ratioDisabledToExisting);
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/prime_hasher_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
