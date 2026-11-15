<?php

declare(strict_types=1);

namespace Prime\Plugins;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Database\Schema\Schema;
use Throwable;

/**
 * Persistence layer for the `pc_plugins` table.
 */
final class PluginRepository
{
    public const TABLE = 'plugins';

    private ?bool $hasTableCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Schema $schema,
    ) {
    }

    public function tableExists(): bool
    {
        if ($this->hasTableCache !== null) {
            return $this->hasTableCache;
        }

        try {
            $this->hasTableCache = $this->schema->hasTable(self::TABLE);
        } catch (Throwable) {
            $this->hasTableCache = false;
        }

        return $this->hasTableCache;
    }

    public function resetCache(): void
    {
        $this->hasTableCache = null;
    }

    /**
     * Return all rows in `pc_plugins` indexed by `slug`.
     *
     * @return array<string, array{
     *     slug: string,
     *     version: string,
     *     status: string,
     *     manifest_hash: string,
     *     last_error: ?string,
     *     errored_at: ?string,
     *     installed_at: string,
     *     activated_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }>
     */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        try {
            $rows = $this->connection->table(self::TABLE)->orderBy('slug', 'ASC')->get();
        } catch (Throwable) {
            return [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            $hydrated = $this->hydrate($row);
            $indexed[$hydrated['slug']] = $hydrated;
        }

        return $indexed;
    }

    /**
     * @return ?array{
     *     slug: string,
     *     version: string,
     *     status: string,
     *     manifest_hash: string,
     *     last_error: ?string,
     *     errored_at: ?string,
     *     installed_at: string,
     *     activated_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function find(string $slug): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = $this->connection->table(self::TABLE)->where('slug', '=', $slug)->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Insert or update a newly installed inactive plugin record.
     */
    public function recordInstalled(string $slug, string $version, string $manifestHash): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $now = $this->nowUtc();
        $existing = $this->find($slug);

        if ($existing === null) {
            $this->connection->table(self::TABLE)->insert([
                'slug' => $slug,
                'version' => $version,
                'status' => PluginInfo::STATUS_INACTIVE,
                'manifest_hash' => $manifestHash,
                'last_error' => null,
                'errored_at' => null,
                'installed_at' => $now,
                'activated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->update([
                'version' => $version,
                'manifest_hash' => $manifestHash,
                'updated_at' => $now,
            ]);
    }

    public function markActive(string $slug, string $version, string $manifestHash): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $now = $this->nowUtc();
        $existing = $this->find($slug);

        if ($existing === null) {
            $this->connection->table(self::TABLE)->insert([
                'slug' => $slug,
                'version' => $version,
                'status' => PluginInfo::STATUS_ACTIVE,
                'manifest_hash' => $manifestHash,
                'last_error' => null,
                'errored_at' => null,
                'installed_at' => $now,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->update([
                'version' => $version,
                'status' => PluginInfo::STATUS_ACTIVE,
                'manifest_hash' => $manifestHash,
                'last_error' => null,
                'errored_at' => null,
                'activated_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function markInactive(string $slug, ?string $version = null, ?string $manifestHash = null): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $now = $this->nowUtc();
        $existing = $this->find($slug);

        if ($existing === null) {
            $this->connection->table(self::TABLE)->insert([
                'slug' => $slug,
                'version' => $version ?? '0.0.0',
                'status' => PluginInfo::STATUS_INACTIVE,
                'manifest_hash' => $manifestHash ?? '',
                'last_error' => null,
                'errored_at' => null,
                'installed_at' => $now,
                'activated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $payload = [
            'status' => PluginInfo::STATUS_INACTIVE,
            'last_error' => null,
            'errored_at' => null,
            'updated_at' => $now,
        ];
        if ($version !== null) {
            $payload['version'] = $version;
        }
        if ($manifestHash !== null) {
            $payload['manifest_hash'] = $manifestHash;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->update($payload);
    }

    public function markErrored(string $slug, string $errorMessage, ?string $version = null, ?string $manifestHash = null): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $now = $this->nowUtc();
        $trimmedError = mb_substr(trim($errorMessage), 0, 4000);
        $existing = $this->find($slug);

        if ($existing === null) {
            $this->connection->table(self::TABLE)->insert([
                'slug' => $slug,
                'version' => $version ?? '0.0.0',
                'status' => PluginInfo::STATUS_ERRORED,
                'manifest_hash' => $manifestHash ?? '',
                'last_error' => $trimmedError,
                'errored_at' => $now,
                'installed_at' => $now,
                'activated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $payload = [
            'status' => PluginInfo::STATUS_ERRORED,
            'last_error' => $trimmedError,
            'errored_at' => $now,
            'updated_at' => $now,
        ];
        if ($version !== null) {
            $payload['version'] = $version;
        }
        if ($manifestHash !== null) {
            $payload['manifest_hash'] = $manifestHash;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->update($payload);
    }

    public function updateVersion(string $slug, string $version, string $manifestHash): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->update([
                'version' => $version,
                'manifest_hash' => $manifestHash,
                'updated_at' => $this->nowUtc(),
            ]);
    }

    public function delete(string $slug): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $this->connection->table(self::TABLE)
            ->where('slug', '=', $slug)
            ->delete();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     slug: string,
     *     version: string,
     *     status: string,
     *     manifest_hash: string,
     *     last_error: ?string,
     *     errored_at: ?string,
     *     installed_at: string,
     *     activated_at: ?string,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    private function hydrate(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'version' => (string) $row['version'],
            'status' => (string) $row['status'],
            'manifest_hash' => (string) ($row['manifest_hash'] ?? ''),
            'last_error' => isset($row['last_error']) && is_string($row['last_error']) ? $row['last_error'] : null,
            'errored_at' => isset($row['errored_at']) && is_string($row['errored_at']) ? $row['errored_at'] : null,
            'installed_at' => (string) ($row['installed_at'] ?? ''),
            'activated_at' => isset($row['activated_at']) && is_string($row['activated_at']) ? $row['activated_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
