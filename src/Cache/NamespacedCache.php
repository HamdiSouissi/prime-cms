<?php

declare(strict_types=1);

namespace Prime\Cache;

/**
 * Cache scope with O(1) namespace invalidation backed by a monotonic version token.
 *
 * Calling `$cache->namespace('menus')->flush()` bumps a single version key instead of
 * scanning or deleting individual cache files.
 */
final class NamespacedCache implements CacheInterface
{
    private readonly string $namespace;

    public function __construct(
        private readonly CacheInterface $store,
        string $namespace,
    ) {
        $cleaned = preg_replace('/[^a-zA-Z0-9._:-]/', '_', trim($namespace)) ?? 'default';
        $this->namespace = $cleaned !== '' ? $cleaned : 'default';
    }

    public function name(): string
    {
        return $this->namespace;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->scopedKey($key), $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->set($this->scopedKey($key), $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->scopedKey($key));
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->scopedKey($key));
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return $this->store->remember($this->scopedKey($key), $ttl, $callback);
    }

    public function increment(string $key, int $amount = 1, ?int $ttl = null): int
    {
        return $this->store->increment($this->scopedKey($key), $amount, $ttl);
    }

    /**
     * Invalidate all keys inside this namespace in O(1) by bumping its version counter.
     */
    public function flush(): bool
    {
        $this->store->increment($this->versionKey(), 1);

        return true;
    }

    public function namespace(string $namespace): NamespacedCache
    {
        return new self($this->store, $this->namespace . ':' . $namespace);
    }

    public function version(): int
    {
        $current = $this->store->get($this->versionKey());
        if (is_int($current) && $current >= 1) {
            return $current;
        }

        $this->store->set($this->versionKey(), 1);

        return 1;
    }

    private function versionKey(): string
    {
        return '_prime_ns_ver:' . $this->namespace;
    }

    private function scopedKey(string $key): string
    {
        return 'ns:' . $this->namespace . ':v' . $this->version() . ':' . $key;
    }
}
