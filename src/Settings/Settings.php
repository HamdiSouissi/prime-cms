<?php

declare(strict_types=1);

namespace Prime\Settings;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Prime\Cache\CacheInterface;
use Prime\Database\Connection;
use Prime\Database\Identifier;
use Prime\Hooks\Hooks;
use Prime\Security\Encrypter;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Persistent JSON-typed key/value settings store backed by `pc_settings`.
 *
 * All `autoload = true` settings are loaded in a single SQL query on first access
 * and cached in memory for the remainder of the request.
 */
final class Settings
{
    private const TABLE = 'settings';

    /**
     * Key used inside the JSON payload to flag an encrypted (secret) value.
     */
    public const ENCRYPTED_MARKER = '__prime_encrypted';

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

    /**
     * @param CacheInterface|null $dataCache Optional PSR-16 style store used by {@see self::remember()}
     * @param Encrypter|null      $crypt     Used to seal `secret` settings (`pc_settings.value` stays opaque)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Hooks $hooks = null,
        private readonly ?CacheInterface $dataCache = null,
        private readonly ?Encrypter $crypt = null,
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

        $this->invalidateRemembered();

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
        $this->invalidateRemembered();

        if ($deleted > 0) {
            $this->hooks?->emit('settings.updated', $name, $oldValue, null);
        }

        return $deleted > 0;
    }

    /**
     * Read a boolean setting, accepting `true`/`false`, `1`/`0`, `'1'`/`'0'` and `'yes'`/`'no'`.
     */
    public function getBool(string $name, bool $default = false): bool
    {
        $value = $this->get($name, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return $default;
    }

    /**
     * Read an integer setting, clamped to an optional inclusive range.
     */
    public function getInt(string $name, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $value = $this->get($name, $default);
        $int = is_numeric($value) ? (int) $value : $default;

        if ($min !== null && $int < $min) {
            return $min;
        }

        if ($max !== null && $int > $max) {
            return $max;
        }

        return $int;
    }

    public function getFloat(string $name, float $default = 0.0): float
    {
        $value = $this->get($name, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function getString(string $name, string $default = ''): string
    {
        $value = $this->get($name, $default);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Read a list- or map-shaped setting, falling back to `$default` for any other type.
     *
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public function getArray(string $name, array $default = []): array
    {
        $value = $this->get($name, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * Persist several settings in one pass, emitting one `settings.updated` event per change.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string> Names whose stored value actually changed
     */
    public function setMany(array $values, bool $autoload = true): array
    {
        $changed = [];

        foreach ($values as $name => $value) {
            $clean = (string) $name;
            if ($this->get($clean) === $value) {
                continue;
            }

            $this->set($clean, $value, $autoload);
            $changed[] = $clean;
        }

        return $changed;
    }

    /**
     * Store a secret in encrypted form (AES-256-GCM through `Prime\Security\Encrypter`).
     */
    public function setSecret(string $name, ?string $plaintext): void
    {
        $encrypter = $this->requireEncrypter();

        if ($plaintext === null || trim($plaintext) === '') {
            $this->delete($name);

            return;
        }

        $this->set($name, [self::ENCRYPTED_MARKER => $encrypter->encrypt($plaintext)]);
    }

    /**
     * Read a setting written by {@see self::setSecret()}, transparently decrypting it.
     */
    public function getSecret(string $name, ?string $default = null): ?string
    {
        $value = $this->get($name);

        if (!is_array($value) || !isset($value[self::ENCRYPTED_MARKER]) || !is_string($value[self::ENCRYPTED_MARKER])) {
            // Tolerate a plain value stored by an older install or a plugin.
            return is_string($value) && $value !== '' ? $value : $default;
        }

        try {
            return $this->requireEncrypter()->decrypt($value[self::ENCRYPTED_MARKER]);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Determine whether a setting is stored encrypted.
     */
    public function isSecret(string $name): bool
    {
        $value = $this->get($name);

        return is_array($value) && array_key_exists(self::ENCRYPTED_MARKER, $value);
    }

    /**
     * Memoize an expensive derived value (a resolved menu, a sitemap payload, a health report) in
     * the shared data cache; `CachePurgeSubscriber` invalidates the `settings` namespace whenever a
     * setting is saved.
     *
     * @template T
     * @param Closure(): T $loader
     * @return T
     */
    public function remember(string $key, ?int $ttl, Closure $loader): mixed
    {
        if ($this->dataCache === null) {
            return $loader();
        }

        return $this->dataCache->namespace('settings')->remember($key, $ttl, $loader);
    }

    /**
     * @param Closure(): array<array-key, mixed> $loader
     * @param array<array-key, mixed>            $default
     *
     * @return array<array-key, mixed>
     */
    public function rememberArray(string $key, ?int $ttl, Closure $loader, array $default = []): array
    {
        $value = $this->remember($key, $ttl, $loader);

        return is_array($value) ? $value : $default;
    }

    /**
     * Drop memoized derived values so readers never see a value computed from stale settings.
     */
    private function invalidateRemembered(): void
    {
        $this->dataCache?->namespace('settings')->flush();
    }

    private function requireEncrypter(): Encrypter
    {
        if ($this->crypt === null) {
            throw new RuntimeException('Aucun chiffreur n\'est configuré : impossible de stocker un secret.');
        }

        return $this->crypt;
    }

    /**
     * Load all settings whose names begin with `$prefix`.
     *
     * @return array<string, mixed>
     */
    public function allByPrefix(string $prefix): array
    {
        if ($prefix === '') {
            return $this->all();
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
        $table = Identifier::quoteSimple($this->connection->prefixed(self::TABLE));
        $rows = $this->connection->select(
            sprintf("SELECT `name`, `value` FROM %s WHERE `name` LIKE ? ESCAPE '!' ORDER BY `name` ASC", $table),
            [$escaped . '%'],
        );

        $result = [];
        foreach ($rows as $row) {
            $key = (string) $row['name'];
            $decoded = $this->decodeValue($row['value'] ?? null);
            $result[$key] = $decoded;
            $this->cache[$key] = $decoded;
            unset($this->missing[$key]);
        }

        return $result;
    }

    /**
     * Delete all settings whose names begin with `$prefix`. Returns the number of deleted rows.
     */
    public function deleteByPrefix(string $prefix): int
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('Setting prefix must not be empty when deleting by prefix.');
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
        $table = Identifier::quoteSimple($this->connection->prefixed(self::TABLE));
        $deleted = $this->connection->delete(
            sprintf("DELETE FROM %s WHERE `name` LIKE ? ESCAPE '!'", $table),
            [$escaped . '%'],
        );

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
                $this->missing[$key] = true;
            }
        }

        return $deleted;
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
