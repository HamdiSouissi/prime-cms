<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Security\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, reset_at DATETIME NOT NULL)');

        $this->connection = new Connection(['prefix' => 'pc_'], pdo: $pdo);
    }

    public function testHitsDecayWindowAvailableInAndClearWithInjectableClock(): void
    {
        $now = 1_700_000_000;
        $limiter = new RateLimiter($this->connection, static function () use (&$now): int {
            return $now;
        });

        $key = 'login:admin@example.fr|192.0.2.10';

        $this->assertFalse($limiter->tooManyAttempts($key, 5));
        $this->assertSame(0, $limiter->attempts($key));
        $this->assertSame(0, $limiter->availableIn($key));

        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($i, $limiter->hit($key, 900));
        }

        // Verify stored key is hashed (SHA-256) and never raw email/IP.
        $row = $this->connection->table('rate_limits')->first();
        $this->assertIsArray($row);
        $this->assertSame(hash('sha256', $key), $row['key']);
        $this->assertStringNotContainsString('admin@example.fr', (string) $row['key']);

        $this->assertTrue($limiter->tooManyAttempts($key, 5));
        $this->assertSame(900, $limiter->availableIn($key));

        // Advance clock by 300 seconds — still locked with 600s remaining.
        $now += 300;
        $this->assertTrue($limiter->tooManyAttempts($key, 5));
        $this->assertSame(600, $limiter->availableIn($key));

        // Advance past expiration — window resets automatically.
        $now += 601;
        $this->assertFalse($limiter->tooManyAttempts($key, 5));
        $this->assertSame(0, $limiter->attempts($key));
        $this->assertSame(1, $limiter->hit($key, 900));

        // Clear removes counter immediately.
        $limiter->clear($key);
        $this->assertSame(0, $limiter->attempts($key));
    }
}
