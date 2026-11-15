<?php

declare(strict_types=1);

namespace Prime\Database;

use PDO;
use PDOException;
use PDOStatement;
use Prime\Database\Exception\ConnectionException;
use Prime\Database\Exception\DatabaseException;
use Prime\Database\Exception\QueryException;
use Prime\Database\Query\QueryBuilder;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Throwable;

/**
 * Lazy PDO wrapper for MySQL 8.0+ and MariaDB 10.6+ with savepoint transactions,
 * prepared-only execution, query logging, and strict credential redaction.
 */
final class Connection
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY';

    private readonly string $host;
    private readonly int $port;
    private readonly string $database;
    private readonly string $username;
    private readonly string $password;
    private readonly string $prefix;
    private readonly string $charset;
    private readonly string $collation;

    private ?PDO $pdo = null;

    private int $transactionLevel = 0;

    private bool $loggingEnabled;

    /**
     * @var list<array{sql: string, bindings: list<mixed>, time_ms: float}>
     */
    private array $queryLog = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config = [],
        private readonly ?Hooks $hooks = null,
        private readonly bool $debug = false,
        ?PDO $pdo = null,
    ) {
        $this->host = (string) ($config['host'] ?? '127.0.0.1');
        $this->port = (int) ($config['port'] ?? 3306);
        $this->database = (string) ($config['database'] ?? '');
        $this->username = (string) ($config['username'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $this->prefix = Identifier::validatePrefix((string) ($config['prefix'] ?? 'pc_'));
        $this->charset = Identifier::validateCharsetOrCollation((string) ($config['charset'] ?? 'utf8mb4'));
        $this->collation = Identifier::validateCharsetOrCollation((string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));
        $this->loggingEnabled = $debug;
        $this->pdo = $pdo;
    }

    /**
     * Redact sensitive connection parameters from debug dumps.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => '********',
            'prefix' => $this->prefix,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'connected' => $this->pdo !== null,
            'transactionLevel' => $this->transactionLevel,
        ];
    }

    /**
     * Return the active PDO instance, establishing the connection lazily on first use.
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf('mysql:host=%s;port=%d', $this->host, $this->port);
        if ($this->database !== '') {
            $dsn .= ';dbname=' . $this->database;
        }
        $dsn .= ';charset=' . $this->charset;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            $pdo = new PDO($dsn, $this->username, $this->password, $options);
            $pdo->exec(sprintf("SET NAMES '%s' COLLATE '%s'", $this->charset, $this->collation));
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec(sprintf("SET SESSION sql_mode = '%s'", self::SQL_MODE));
        } catch (PDOException $exception) {
            throw $this->buildConnectionException($exception);
        }

        $this->pdo = $pdo;

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function charset(): string
    {
        return $this->charset;
    }

    public function collation(): string
    {
        return $this->collation;
    }

    public function prefixed(string $table): string
    {
        return Identifier::prefixTable($table, $this->prefix);
    }

    public function driverName(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function serverVersion(): string
    {
        $version = $this->scalar('SELECT VERSION()');

        return is_scalar($version) ? (string) $version : '';
    }

    /**
     * Start a fluent QueryBuilder targeting the given unprefixed table.
     */
    public function table(string $name): QueryBuilder
    {
        return new QueryBuilder($this, $name);
    }

    /**
     * Create a Schema builder bound to this connection.
     */
    public function schema(): Schema
    {
        return new Schema($this);
    }

    /**
     * Execute a SELECT statement and return all rows as associative arrays.
     *
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Execute a SELECT statement and return the first row or null.
     *
     * @param list<mixed> $bindings
     * @return ?array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $statement = $this->run($sql, $bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Execute a SELECT statement and return the first column of the first row, or null.
     *
     * @param list<mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = $this->run($sql, $bindings);
        $row = $statement->fetch(PDO::FETCH_NUM);

        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return $row[0];
    }

    /**
     * Execute an INSERT statement and return the generated auto-increment ID.
     *
     * @param list<mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);
        $lastId = $this->pdo()->lastInsertId();

        return $lastId === false ? 0 : (int) $lastId;
    }

    /**
     * Execute an UPDATE statement and return the number of affected rows.
     *
     * @param list<mixed> $bindings
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Execute a DELETE statement and return the number of affected rows.
     *
     * @param list<mixed> $bindings
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Execute a general SQL statement (DDL, lock, session command, etc.).
     *
     * @param list<mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->run($sql, $bindings);

        return true;
    }

    /**
     * Begin a transaction or create a nested savepoint.
     */
    public function beginTransaction(): void
    {
        $pdo = $this->pdo();

        try {
            if ($this->transactionLevel === 0) {
                $pdo->beginTransaction();
            } else {
                $savepoint = 'pc_sp_' . $this->transactionLevel;
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
        } catch (PDOException $exception) {
            throw $this->buildQueryException($exception, 'BEGIN / SAVEPOINT');
        }

        $this->transactionLevel++;
    }

    /**
     * Commit the current transaction or release the active nested savepoint.
     */
    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            throw new DatabaseException('Cannot commit: no active database transaction.');
        }

        $this->transactionLevel--;
        $pdo = $this->pdo();

        try {
            if ($this->transactionLevel === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } else {
                $savepoint = 'pc_sp_' . $this->transactionLevel;
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (PDOException $exception) {
            throw $this->buildQueryException($exception, 'COMMIT / RELEASE SAVEPOINT');
        }
    }

    /**
     * Roll back the current transaction or revert to the active nested savepoint.
     */
    public function rollBack(): void
    {
        if ($this->transactionLevel <= 0) {
            throw new DatabaseException('Cannot roll back: no active database transaction.');
        }

        $this->transactionLevel--;
        $pdo = $this->pdo();

        try {
            if ($this->transactionLevel === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $savepoint = 'pc_sp_' . $this->transactionLevel;
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
        } catch (PDOException $exception) {
            throw $this->buildQueryException($exception, 'ROLLBACK / ROLLBACK TO SAVEPOINT');
        }
    }

    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Execute a callable inside a transaction (or savepoint when nested),
     * committing automatically on success and rolling back on any throwable.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
    }

    /**
     * Enable or disable in-memory query logging.
     */
    public function enableQueryLog(bool $enabled = true): void
    {
        $this->loggingEnabled = $enabled;
    }

    /**
     * Return all logged queries (active by default when debug mode is enabled).
     *
     * @return list<array{sql: string, bindings: list<mixed>, time_ms: float}>
     */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Prepare, bind, execute, time, and log a SQL statement.
     *
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $pdo = $this->pdo();
        $startedAt = microtime(true);

        try {
            $statement = $pdo->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
        } catch (PDOException $exception) {
            throw $this->buildQueryException($exception, $sql);
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000, 3);

        if ($this->loggingEnabled) {
            $this->queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time_ms' => $durationMs,
            ];
        }

        $this->hooks?->emit('db.query', $sql, $bindings, $durationMs);

        return $statement;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $index => $value) {
            $position = $index + 1;

            if ($value === null) {
                $statement->bindValue($position, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $statement->bindValue($position, $value ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $statement->bindValue($position, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue($position, (string) $value, PDO::PARAM_STR);
            }
        }
    }

    private function buildConnectionException(PDOException $exception): ConnectionException
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = is_array($errorInfo) && isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $exception->getCode();
        $driverCode = is_array($errorInfo) && isset($errorInfo[1]) ? (int) $errorInfo[1] : 0;

        if ($this->debug) {
            $sanitized = $this->redactSecrets($exception->getMessage());
            $message = sprintf('Database connection failed [%s]: %s', $sqlState, $sanitized);
        } else {
            $message = 'Unable to establish a database connection.';
        }

        return new ConnectionException($message, $sqlState, $driverCode);
    }

    private function buildQueryException(PDOException $exception, string $sql): QueryException
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = is_array($errorInfo) && isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $exception->getCode();

        if ($this->debug) {
            $sanitized = $this->redactSecrets($exception->getMessage());
            $message = sprintf('Database query failed [%s]: %s (SQL: %s)', $sqlState, $sanitized, $sql);
        } else {
            $message = 'A database query error occurred.';
        }

        return new QueryException($message, $this->debug ? $sql : '', $sqlState);
    }

    private function redactSecrets(string $message): string
    {
        $redacted = $message;

        if ($this->password !== '') {
            $redacted = str_replace($this->password, '********', $redacted);
        }

        // Remove any raw DSN fragment if the driver included one.
        $cleaned = preg_replace('/mysql:[^\s\)]+/i', 'mysql:[redacted]', $redacted);

        return $cleaned ?? $redacted;
    }
}
