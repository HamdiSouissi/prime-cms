<?php

declare(strict_types=1);

namespace Prime\Database\Migrations;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

/**
 * Stores and queries executed migration batches inside the `pc_migrations` table,
 * tracking each migration's `source` (`core` or a plugin slug).
 */
final class MigrationRepository
{
    public const TABLE = 'migrations';
    public const DEFAULT_SOURCE = 'core';

    public function __construct(
        private readonly Connection $connection,
        private readonly Schema $schema,
    ) {
    }

    public function tableExists(): bool
    {
        return $this->schema->hasTable(self::TABLE);
    }

    public function hasSourceColumn(): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->schema->hasColumn(self::TABLE, 'source');
    }

    public function ensureTableExists(): void
    {
        if ($this->tableExists()) {
            return;
        }

        $this->schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration', 191)->unique();
            $table->string('source', 64)->default(self::DEFAULT_SOURCE);
            $table->integer('batch')->unsigned();
            $table->dateTime('ran_at');
            $table->index('source', 'idx_pc_migrations_source');
        });
    }

    /**
     * Return executed migrations keyed by migration name (optionally filtered by `$source`).
     *
     * @return array<string, array{id: int, migration: string, source: string, batch: int, ran_at: string}>
     */
    public function ran(?string $source = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = $this->connection->table(self::TABLE);
        if ($source !== null && $this->hasSourceColumn()) {
            $query->where('source', '=', $source);
        } elseif ($source !== null && $source !== self::DEFAULT_SOURCE) {
            return [];
        }

        $rows = $query
            ->orderBy('batch', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $indexed = [];
        foreach ($rows as $row) {
            $name = (string) $row['migration'];
            $indexed[$name] = [
                'id' => (int) $row['id'],
                'migration' => $name,
                'source' => isset($row['source']) && is_string($row['source']) && $row['source'] !== ''
                    ? $row['source']
                    : self::DEFAULT_SOURCE,
                'batch' => (int) $row['batch'],
                'ran_at' => (string) $row['ran_at'],
            ];
        }

        return $indexed;
    }

    public function lastBatchNumber(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $max = $this->connection->table(self::TABLE)->max('batch');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * Return migrations belonging to the last `$steps` batches in reverse execution order.
     *
     * @return list<array{id: int, migration: string, source: string, batch: int, ran_at: string}>
     */
    public function lastBatches(int $steps = 1): array
    {
        if (!$this->tableExists() || $steps < 1) {
            return [];
        }

        $batchRows = $this->connection->table(self::TABLE)
            ->distinct()
            ->select('batch')
            ->orderBy('batch', 'DESC')
            ->limit($steps)
            ->get();

        if ($batchRows === []) {
            return [];
        }

        $batches = [];
        foreach ($batchRows as $row) {
            $batches[] = (int) $row['batch'];
        }

        $rows = $this->connection->table(self::TABLE)
            ->whereIn('batch', $batches)
            ->orderBy('batch', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return $this->hydrateRows($rows);
    }

    /**
     * Return executed migrations for a specific `$source` and `$batch` in reverse execution order.
     *
     * @return list<array{id: int, migration: string, source: string, batch: int, ran_at: string}>
     */
    public function batchForSource(string $source, int $batch): array
    {
        if (!$this->tableExists() || !$this->hasSourceColumn()) {
            return [];
        }

        $rows = $this->connection->table(self::TABLE)
            ->where('source', '=', $source)
            ->where('batch', '=', $batch)
            ->orderBy('id', 'DESC')
            ->get();

        return $this->hydrateRows($rows);
    }

    /**
     * Return all ran migrations in reverse execution order (optionally scoped to `$source`).
     *
     * @return list<array{id: int, migration: string, source: string, batch: int, ran_at: string}>
     */
    public function allDescending(?string $source = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = $this->connection->table(self::TABLE);
        if ($source !== null) {
            if (!$this->hasSourceColumn()) {
                return [];
            }
            $query->where('source', '=', $source);
        }

        $rows = $query
            ->orderBy('batch', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return $this->hydrateRows($rows);
    }

    public function log(string $migration, int $batch, string $source = self::DEFAULT_SOURCE): void
    {
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $payload = [
            'migration' => $migration,
            'batch' => $batch,
            'ran_at' => $nowUtc,
        ];

        if ($this->hasSourceColumn()) {
            $payload['source'] = $source;
        }

        $this->connection->table(self::TABLE)->insert($payload);
    }

    public function delete(string $migration): void
    {
        $this->connection->table(self::TABLE)
            ->where('migration', '=', $migration)
            ->delete();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, migration: string, source: string, batch: int, ran_at: string}>
     */
    private function hydrateRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'migration' => (string) $row['migration'],
                'source' => isset($row['source']) && is_string($row['source']) && $row['source'] !== ''
                    ? $row['source']
                    : self::DEFAULT_SOURCE,
                'batch' => (int) $row['batch'],
                'ran_at' => (string) $row['ran_at'],
            ];
        }

        return $result;
    }
}
