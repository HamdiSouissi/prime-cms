<?php

declare(strict_types=1);

namespace Prime\Settings;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Prime\Database\Connection;
use Prime\Database\Identifier;
use Prime\Hooks\Hooks;

/**
 * Persistent JSON-typed key/value settings store backed by `pc_settings`.
 *
 * All `autoload = true` settings are loaded in a single SQL query on first access
 * and cached in memory for the remainder of the request.
 */
final class Settings
{
    private const TABLE = 'settings';

    private bool $autoloaded = false;

    /**
     * Cached setting values keyed by name (including explicitly stored `null` values).
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Settings already checked against the database and known not to exist.
     *
     * @var array<string, true>
     */
    private array $missing = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Retrieve a setting value or `$default` when not present.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $this->ensureAutoloaded();

        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        if (isset($this->missing[$name])) {
            return $default;
        }

        $row = $this->connection->table(self::TABLE)
            ->select('value')
            ->where('name', '=', $name)
            ->first();

        if ($row === null) {
            $this->missing[$name] = true;

            return $default;
        }

        $decoded = $this->decodeValue($row['value'] ?? null);
        $this->cache[$name] = $decoded;

        return $decoded;
    }

    /**
     * Determine whether a setting exists in the store (even if its value is `null`).
     */
    public function has(string $name): bool
    {
        $this->ensureAutoloaded();

        if (array_key_exists($name, $this->cache)) {
            return true;
        }

        if (isset($this->missing[$name])) {
            return false;
        }

        $row = $this->connection->table(self::TABLE)
            ->select('value')
            ->where('name', '=', $name)
            ->first();

        if ($row === null) {
            $this->missing[$name] = true;

            return false;
        }

        $this->cache[$name] = $this->decodeValue($row['value'] ?? null);

        return true;
    }

    /**
     * Create or update a setting, preserving its PHP type via JSON serialization.
     */
    public function set(string $name, mixed $value, bool $autoload = true): void
    {
        $cleanName = trim($name);
        if ($cleanName === '' || strlen($cleanName) > 191) {
            throw new InvalidArgumentException('Setting name must be between 1 and 191 characters.');
        }

        $oldValue = $this->has($cleanName) ? $this->get($cleanName) : null;
        $encoded = $this->encodeValue($value);
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $table = Identifier::quoteSimple($this->connection->prefixed(self::TABLE));
        $autoloadInt = $autoload ? 1 : 0;

        $sql = sprintf(
            'INSERT INTO %s (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `value` = ?, `autoload` = ?, `updated_at` = ?',
            $table,
        );

        $this->connection->statement($sql, [
            $cleanName,
            $encoded,
            $autoloadInt,
            $nowUtc,
            $nowUtc,
            $encoded,
            $autoloadInt,
            $nowUtc,
        ]);

        $this->cache[$cleanName] = $value;
        unset($this->missing[$cleanName]);

        $this->hooks?->emit('settings.updated', $cleanName, $oldValue, $value);
    }

    /**
     * Delete a setting from the store. Returns true if a row was removed.
     */
    public function delete(string $name): bool
    {
        $existed = $this->has($name);
        $oldValue = $existed ? $this->get($name) : null;

        $deleted = $this->connection->table(self::TABLE)
            ->where('name', '=', $name)
            ->delete();

        unset($this->cache[$name]);
        $this->missing[$name] = true;

        if ($deleted > 0) {
            $this->hooks?->emit('settings.updated', $name, $oldValue, null);
        }

        return $deleted > 0;
    }

    /**
     * Load and return all stored settings (both autoloaded and lazy).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = $this->connection->table(self::TABLE)
            ->select('name', 'value')
            ->orderBy('name', 'ASC')
            ->get();

        $all = [];
        foreach ($rows as $row) {
            $key = (string) $row['name'];
            $decoded = $this->decodeValue($row['value'] ?? null);
            $all[$key] = $decoded;
            $this->cache[$key] = $decoded;
            unset($this->missing[$key]);
        }

        $this->autoloaded = true;

        return $all;
    }

    /**
     * Clear the in-memory settings cache so subsequent reads query the database.
     */
    public function flushCache(): void
    {
        $this->autoloaded = false;
        $this->cache = [];
        $this->missing = [];
    }

    private function ensureAutoloaded(): void
    {
        if ($this->autoloaded) {
            return;
        }

        $rows = $this->connection->table(self::TABLE)
            ->select('name', 'value')
            ->where('autoload', '=', 1)
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row['name'];
            $this->cache[$key] = $this->decodeValue($row['value'] ?? null);
            unset($this->missing[$key]);
        }

        $this->autoloaded = true;
    }

    private function encodeValue(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Setting value cannot be JSON-encoded: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function decodeValue(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            return $raw;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw;
        }
    }
}
