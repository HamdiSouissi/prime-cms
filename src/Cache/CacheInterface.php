<?php

declare(strict_types=1);

namespace Prime\Cache;

/**
 * Unified data cache contract for Prime CMS with O(1) namespace invalidation.
 */
interface CacheInterface
{
    /**
     * Retrieve a cached value or `$default` when missing or expired.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a JSON-serializable value with an optional TTL in seconds (`null` = no expiry, `<= 0` = delete).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Determine whether an unexpired entry exists for `$key`.
     */
    public function has(string $key): bool;

    /**
     * Remove a single key from the cache.
     */
    public function delete(string $key): bool;

    /**
     * Return the cached value or compute, store, and return it on a miss.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed;

    /**
     * Atomically increment an integer counter and return the new value.
     */
    public function increment(string $key, int $amount = 1, ?int $ttl = null): int;

    /**
     * Flush all entries in this cache store (or namespace).
     */
    public function flush(): bool;

    /**
     * Return a scoped cache view backed by an O(1) versioned namespace.
     */
    public function namespace(string $namespace): NamespacedCache;
}
