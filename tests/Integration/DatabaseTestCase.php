<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Database\Identifier;
use Throwable;

/**
 * Shared base class for real MySQL / MariaDB integration tests.
 *
 * Reads credentials from `PRIME_TEST_DB_*` environment variables and isolates
 * every test method using a unique table prefix dropped automatically in `tearDown()`.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected string $prefix = '';

    /**
     * @var array{host: string, port: int, database: string, username: string, password: string, prefix: string}
     */
    protected array $dbConfig = [
        'host' => '',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
        'prefix' => 'pc_',
    ];

    /**
     * Number of core migration files shipped in `database/migrations`.
     *
     * Hard-coded totals rot as soon as a new migration lands, so the file system stays
     * the single source of truth for what `Migrator::run()` is expected to apply.
     */
    protected function coreMigrationCount(): int
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');

        return count($files === false ? [] : $files);
    }

    protected function setUp(): void
    {
        $host = $this->envVar('PRIME_TEST_DB_HOST');
        if ($host === '') {
            $this->markTestSkipped('PRIME_TEST_DB_HOST is not configured; skipping database integration test.');
        }

        $portRaw = $this->envVar('PRIME_TEST_DB_PORT', '3306');
        $database = $this->envVar('PRIME_TEST_DB_DATABASE', 'prime_test');
        $username = $this->envVar('PRIME_TEST_DB_USERNAME', 'root');
        $password = $this->envVar('PRIME_TEST_DB_PASSWORD', '');

        $this->prefix = 'pt' . substr(bin2hex(random_bytes(3)), 0, 6) . '_';

        $this->dbConfig = [
            'host' => $host,
            'port' => (int) $portRaw,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'prefix' => $this->prefix,
        ];

        $this->connection = new Connection($this->dbConfig, debug: true);
    }

    protected function tearDown(): void
    {
        if ($this->prefix !== '') {
            try {
                $this->dropTablesWithPrefix($this->connection, $this->prefix);
            } catch (Throwable) {
                // Best-effort cleanup if connection was already torn down.
            }
        }
    }

    protected function dropTablesWithPrefix(Connection $connection, string $prefix): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $rows = $connection->select(
            "SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` LIKE ? ESCAPE '\\\\'",
            [$escaped . '%'],
        );

        if ($rows === []) {
            return;
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($rows as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
                if ($table !== '' && str_starts_with($table, $prefix)) {
                    $connection->statement('DROP TABLE IF EXISTS ' . Identifier::quoteSimple($table));
                }
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function envVar(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }

        if (isset($_ENV[$name]) && is_scalar($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        return $default;
    }
}
