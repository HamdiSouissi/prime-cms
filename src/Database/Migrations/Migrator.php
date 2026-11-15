<?php

declare(strict_types=1);

namespace Prime\Database\Migrations;

use Prime\Database\Connection;
use Prime\Database\Exception\MigrationException;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Throwable;

/**
 * Discovers, locks, executes, rolls back, and reports versioned database migrations.
 *
 * Note on MySQL DDL behavior: DDL statements (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`)
 * trigger an implicit commit in MySQL/MariaDB. Consequently, each migration is recorded in
 * `pc_migrations` immediately after its `up()` completes. If a later migration fails, execution
 * stops cleanly with an exception identifying the failing migration while preserving the
 * history of migrations that already succeeded.
 */
final class Migrator
{
    private const FILENAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/';

    private readonly MigrationRepository $repository;

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Schema $schema,
        private array $paths = [],
        private readonly ?Hooks $hooks = null,
        private readonly int $lockTimeoutSeconds = 5,
    ) {
        $this->repository = new MigrationRepository($connection, $schema);
    }

    public function addPath(string $path): void
    {
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    /**
     * Return all migration directories after applying the `migrations.paths` filter hook.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = $this->paths;
        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('migrations.paths', $paths);
            if (is_array($filtered)) {
                $paths = [];
                foreach ($filtered as $item) {
                    if (is_string($item) && $item !== '') {
                        $paths[] = $item;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Discover all valid migration files across configured paths, sorted chronologically.
     *
     * @return array<string, string> Map of migration name => absolute file path
     */
    public function discover(): array
    {
        $files = [];

        foreach ($this->paths() as $directory) {
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (preg_match(self::FILENAME_PATTERN, $entry) !== 1) {
                    continue;
                }

                $name = substr($entry, 0, -4);
                $fullPath = rtrim($directory, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $entry;

                if (isset($files[$name])) {
                    throw new MigrationException(sprintf(
                        'Duplicate migration [%s] found in [%s] and [%s].',
                        $name,
                        $files[$name],
                        $fullPath,
                    ));
                }

                $files[$name] = $fullPath;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Execute all pending migrations in chronological order inside a single new batch.
     *
     * @return list<string> Executed migration names
     */
    public function run(): array
    {
        return $this->withLock(function (): array {
            $this->repository->ensureTableExists();

            $discovered = $this->discover();
            $ran = $this->repository->ran();
            $pending = array_diff_key($discovered, $ran);

            if ($pending === []) {
                return [];
            }

            $batch = $this->repository->lastBatchNumber() + 1;
            $executed = [];

            foreach ($pending as $name => $path) {
                $migration = $this->loadMigration($name, $path);

                try {
                    $migration->up($this->schema);
                } catch (Throwable $exception) {
                    throw new MigrationException(
                        sprintf('Migration [%s] failed during up(): %s', $name, $exception->getMessage()),
                        0,
                        $exception,
                    );
                }

                $this->repository->log($name, $batch);
                $executed[] = $name;
            }

            return $executed;
        });
    }

    /**
     * Roll back the last `$steps` migration batches in reverse order.
     *
     * @return list<string> Rolled-back migration names
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            return [];
        }

        return $this->withLock(function () use ($steps): array {
            $targets = $this->repository->lastBatches($steps);
            if ($targets === []) {
                return [];
            }

            return $this->rollbackEntries($targets);
        });
    }

    /**
     * Roll back every executed migration in reverse order.
     *
     * @return list<string> Rolled-back migration names
     */
    public function reset(): array
    {
        return $this->withLock(function (): array {
            $targets = $this->repository->allDescending();
            if ($targets === []) {
                return [];
            }

            return $this->rollbackEntries($targets);
        });
    }

    /**
     * Drop all tables matching the connection prefix and re-run all migrations.
     *
     * @return list<string> Executed migration names
     */
    public function fresh(): array
    {
        $this->withLock(function (): void {
            $this->schema->dropAllTablesWithPrefix();
        });

        return $this->run();
    }

    /**
     * Return status metadata for every discovered and previously executed migration.
     *
     * @return list<array{migration: string, status: string, batch: ?int, ran_at: ?string, path: ?string}>
     */
    public function status(): array
    {
        $discovered = $this->discover();
        $ran = $this->repository->ran();

        $allNames = array_values(array_unique(array_merge(array_keys($discovered), array_keys($ran))));
        sort($allNames, SORT_STRING);

        $rows = [];
        foreach ($allNames as $name) {
            $isRan = isset($ran[$name]);
            $rows[] = [
                'migration' => $name,
                'status' => $isRan ? 'ran' : 'pending',
                'batch' => $isRan ? $ran[$name]['batch'] : null,
                'ran_at' => $isRan ? $ran[$name]['ran_at'] : null,
                'path' => $discovered[$name] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Compute the MySQL GET_LOCK identifier scoped to the current database and table prefix.
     */
    public function lockName(): string
    {
        return 'prime_mig_' . sha1($this->connection->database() . ':' . $this->connection->prefix());
    }

    /**
     * @param list<array{id: int, migration: string, batch: int, ran_at: string}> $entries
     * @return list<string>
     */
    private function rollbackEntries(array $entries): array
    {
        $discovered = $this->discover();
        $rolledBack = [];

        foreach ($entries as $entry) {
            $name = $entry['migration'];
            if (!isset($discovered[$name])) {
                throw new MigrationException(sprintf('Cannot roll back migration [%s]: migration file not found.', $name));
            }

            $migration = $this->loadMigration($name, $discovered[$name]);

            try {
                $migration->down($this->schema);
            } catch (Throwable $exception) {
                throw new MigrationException(
                    sprintf('Migration [%s] failed during down(): %s', $name, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            $this->repository->delete($name);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    private function loadMigration(string $name, string $path): Migration
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MigrationException(sprintf('Migration file [%s] is not readable.', $path));
        }

        $instance = require $path;

        if (!$instance instanceof Migration) {
            throw new MigrationException(sprintf(
                'Migration [%s] in [%s] must return an instance of %s.',
                $name,
                $path,
                Migration::class,
            ));
        }

        return $instance;
    }

    /**
     * Acquire a MySQL advisory lock around migration execution to prevent concurrent runs.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $usesMysqlLock = $this->connection->driverName() === 'mysql';
        $lockName = $this->lockName();

        if ($usesMysqlLock) {
            $acquired = $this->connection->scalar(
                'SELECT GET_LOCK(?, ?)',
                [$lockName, max(0, $this->lockTimeoutSeconds)],
            );

            if ((int) $acquired !== 1) {
                throw new MigrationException(sprintf(
                    'Unable to acquire migration lock [%s]. Another migration process may be running.',
                    $lockName,
                ));
            }
        }

        try {
            return $callback();
        } finally {
            if ($usesMysqlLock) {
                try {
                    $this->connection->scalar('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (Throwable) {
                    // Connection may already have been lost; MySQL releases session locks automatically.
                }
            }
        }
    }
}
