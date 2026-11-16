<?php

declare(strict_types=1);

namespace Prime\Cache;

/**
 * APCu shared-memory cache driver, used when the `apcu` extension is enabled for the current SAPI.
 *
 * All calls are guarded by {@see self::isAvailable()} so that the class can be loaded on runtimes
 * without the extension (the container only instantiates it when APCu is usable).
 */
final class ApcuCache implements CacheInterface
{
    public function __construct(
        private readonly string $prefix = 'prime_cms:',
    ) {
    }

    /**
     * Determine whether APCu is loaded and enabled for the running SAPI.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_fetch')) {
            return false;
        }

        $flag = PHP_SAPI === 'cli' ? ini_get('apc.enable_cli') : ini_get('apc.enabled');

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!self::isAvailable()) {
            return $default;
        }

        $value = apcu_fetch($this->prefix . $key, $success);

        return $success === true ? $value : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            return $this->delete($key);
        }

        if (!self::isAvailable()) {
            return false;
        }

        return apcu_store($this->prefix . $key, $value, $ttl ?? 0);
    }

    public function has(string $key): bool
    {
        if (!self::isAvailable() || !function_exists('apcu_exists')) {
            return false;
        }

        return apcu_exists($this->prefix . $key);
    }

    public function delete(string $key): bool
    {
        if (!self::isAvailable() || !function_exists('apcu_delete')) {
            return false;
        }

        return apcu_delete($this->prefix . $key);
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $sentinel = "\0__prime_miss__";
        $cached = $this->get($key, $sentinel);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $amount = 1, ?int $ttl = null): int
    {
        $current = $this->get($key);
        $next = (is_int($current) ? $current : (int) (is_numeric($current) ? $current : 0)) + $amount;
        $this->set($key, $next, $ttl);

        return $next;
    }

    /**
     * Clear the whole APCu user cache.
     *
     * Namespace isolation is handled by {@see NamespacedCache} (version bump), so targeted
     * invalidation never reaches this method.
     */
    public function flush(): bool
    {
        if (!self::isAvailable() || !function_exists('apcu_clear_cache')) {
            return false;
        }

        return apcu_clear_cache();
    }

    public function namespace(string $namespace): NamespacedCache
    {
        return new NamespacedCache($this, $namespace);
    }

    /**
     * Return entry count and shared memory usage reported by APCu.
     *
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        if (!self::isAvailable() || !function_exists('apcu_cache_info')) {
            return ['entries' => 0, 'bytes' => 0];
        }

        $info = @apcu_cache_info(true);
        if (!is_array($info)) {
            return ['entries' => 0, 'bytes' => 0];
        }

        return [
            'entries' => isset($info['num_entries']) ? (int) $info['num_entries'] : 0,
            'bytes' => isset($info['mem_size']) ? (int) $info['mem_size'] : 0,
        ];
    }
}
