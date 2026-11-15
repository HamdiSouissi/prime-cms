<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Database\Exception\ConnectionException;
use Prime\Database\Exception\InvalidIdentifierException;
use Prime\Database\Exception\QueryException;
use Prime\Hooks\Hooks;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    public function testConnectionIsLazyAndRedactsPasswordInDebugInfoAndExceptions(): void
    {
        $secret = 'SuperSecretDbPass#2026!';
        $connection = new Connection([
            'host' => '127.0.0.1',
            'port' => 65432,
            'database' => 'nonexistent_prime_db',
            'username' => 'prime_user',
            'password' => $secret,
            'prefix' => 'pc_',
        ], debug: false);

        $this->assertFalse($connection->isConnected());
        $this->assertSame('pc_', $connection->prefix());
        $this->assertSame('pc_users', $connection->prefixed('users'));
        $this->assertSame('pc_users', $connection->prefixed('pc_users'));

        $debugInfo = $connection->__debugInfo();
        $this->assertSame('********', $debugInfo['password']);

        try {
            $connection->pdo();
            $this->fail('Expected ConnectionException for unreachable port.');
        } catch (ConnectionException $exception) {
            $this->assertStringNotContainsString($secret, $exception->getMessage());
            $this->assertStringNotContainsString('mysql:', $exception->getMessage());
            $this->assertSame('Unable to establish a database connection.', $exception->getMessage());
        }
    }

    public function testInvalidCharsetOrPrefixThrowsInvalidIdentifierException(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new Connection([
            'charset' => 'utf8mb4; DROP TABLE pc_users',
        ]);
    }

    public function testTransactionsNestedSavepointsQueryLogAndHook(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $hooks = new Hooks();
        $hookedQueries = [];
        $hooks->on('db.query', static function (string $sql, array $bindings, float $timeMs) use (&$hookedQueries): void {
            $hookedQueries[] = ['sql' => $sql, 'bindings' => $bindings, 'time_ms' => $timeMs];
        });

        $conn = new Connection(['prefix' => 'pc_'], hooks: $hooks, debug: true, pdo: $pdo);
        $this->assertTrue($conn->isConnected());

        $conn->statement('CREATE TABLE `pc_items` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT NOT NULL, `qty` INTEGER NOT NULL DEFAULT 0)');

        $id1 = $conn->insert('INSERT INTO `pc_items` (`name`, `qty`) VALUES (?, ?)', ['Alpha', 10]);
        $this->assertSame(1, $id1);

        // Outer transaction commits while inner savepoint rolls back cleanly.
        $conn->transaction(static function (Connection $db): void {
            $db->insert('INSERT INTO `pc_items` (`name`, `qty`) VALUES (?, ?)', ['OuterKept', 5]);

            try {
                $db->transaction(static function (Connection $inner): void {
                    $inner->insert('INSERT INTO `pc_items` (`name`, `qty`) VALUES (?, ?)', ['InnerRolledBack', 99]);
                    throw new RuntimeException('Abort inner savepoint');
                });
            } catch (RuntimeException) {
                // Intentionally swallowed after inner savepoint rolled back.
            }
        });

        $this->assertSame(0, $conn->transactionLevel());
        $names = $conn->table('items')->orderBy('id', 'ASC')->pluck('name');
        $this->assertSame(['Alpha', 'OuterKept'], $names);

        $this->assertNotEmpty($conn->queryLog());
        $this->assertNotEmpty($hookedQueries);
        $this->assertSame(2, $conn->table('items')->count());
        $this->assertSame('Alpha', $conn->scalar('SELECT `name` FROM `pc_items` WHERE `id` = ?', [1]));
        $this->assertNull($conn->scalar('SELECT `name` FROM `pc_items` WHERE `id` = ?', [999]));
    }

    public function testQueryExceptionSanitizesErrorInProductionMode(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $conn = new Connection(['prefix' => 'pc_'], debug: false, pdo: $pdo);

        try {
            $conn->select('SELECT * FROM `nonexistent_table`');
            $this->fail('Expected QueryException.');
        } catch (QueryException $exception) {
            $this->assertSame('A database query error occurred.', $exception->getMessage());
            $this->assertSame('', $exception->sql());
        }
    }
}
