<?php

declare(strict_types=1);

namespace Prime\Database\Schema;

use Prime\Database\Connection;
use Prime\Database\Identifier;

/**
 * High-level MySQL/MariaDB schema manager executing Blueprint DDL statements.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Create a new table using the provided Blueprint callback.
     *
     * @param callable(Blueprint): void $callback
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint(
            table: $table,
            creating: true,
            prefix: $this->connection->prefix(),
            charset: $this->connection->charset(),
            collation: $this->connection->collation(),
        );

        $callback($blueprint);

        foreach ($blueprint->toSql() as $sql) {
            $this->connection->statement($sql);
        }
    }

    /**
     * Modify an existing table using the provided Blueprint callback.
     *
     * @param callable(Blueprint): void $callback
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint(
            table: $table,
            creating: false,
            prefix: $this->connection->prefix(),
            charset: $this->connection->charset(),
            collation: $this->connection->collation(),
        );

        $callback($blueprint);

        foreach ($blueprint->toSql() as $sql) {
            $this->connection->statement($sql);
        }
    }

    public function drop(string $table): void
    {
        $prefixed = $this->connection->prefixed($table);
        $this->connection->statement('DROP TABLE ' . Identifier::quoteSimple($prefixed));
    }

    public function dropIfExists(string $table): void
    {
        $prefixed = $this->connection->prefixed($table);
        $this->connection->statement('DROP TABLE IF EXISTS ' . Identifier::quoteSimple($prefixed));
    }

    public function rename(string $from, string $to): void
    {
        $fromTable = $this->connection->prefixed($from);
        $toTable = $this->connection->prefixed($to);

        $this->connection->statement(sprintf(
            'RENAME TABLE %s TO %s',
            Identifier::quoteSimple($fromTable),
            Identifier::quoteSimple($toTable),
        ));
    }

    public function hasTable(string $table): bool
    {
        $prefixed = $this->connection->prefixed($table);
        $count = $this->connection->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$prefixed],
        );

        return is_numeric($count) && (int) $count > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $prefixed = $this->connection->prefixed($table);
        $cleanColumn = Identifier::validateSimple($column);

        $count = $this->connection->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$prefixed, $cleanColumn],
        );

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * List all tables in the active database matching the configured (or given) prefix.
     *
     * @return list<string>
     */
    public function tablesWithPrefix(?string $prefix = null): array
    {
        $targetPrefix = Identifier::validatePrefix($prefix ?? $this->connection->prefix());
        $escapedPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $targetPrefix);

        $rows = $this->connection->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ESCAPE '\\\\' ORDER BY TABLE_NAME ASC",
            [$escapedPrefix . '%'],
        );

        $tables = [];
        foreach ($rows as $row) {
            $name = $row['TABLE_NAME'] ?? $row['table_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * Drop all tables in the active database matching the prefix, temporarily
     * disabling foreign key checks so dependent tables are removed cleanly.
     */
    public function dropAllTablesWithPrefix(?string $prefix = null): int
    {
        $tables = $this->tablesWithPrefix($prefix);
        if ($tables === []) {
            return 0;
        }

        $this->connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $tableName) {
                $this->connection->statement('DROP TABLE IF EXISTS ' . Identifier::quoteSimple($tableName));
            }
        } finally {
            $this->connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($tables);
    }
}
