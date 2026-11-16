<?php

declare(strict_types=1);

namespace Prime\Cache;

use Closure;

/**
 * In-memory cache driver primarily intended for automated tests and isolated executions.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, array{value: mixed, expires_at: ?int}>
     */
    private array $items = [];

    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Optional deterministic Unix timestamp clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->items)) {
            return $default;
        }

        $entry = $this->items[$key];
        if ($entry['expires_at'] !== null && $entry['expires_at'] <= ($this->clock)()) {
            unset($this->items[$key]);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            $this->delete($key);

            return true;
        }

        $this->items[$key] = [
            'value' => $value,
            'expires_at' => $ttl !== null ? ($this->clock)() + $ttl : null,
        ];

        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        $entry = $this->items[$key];
        if ($entry['expires_at'] !== null && $entry['expires_at'] <= ($this->clock)()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $amount = 1, ?int $ttl = null): int
    {
        $current = $this->get($key, 0);
        $next = (is_int($current) ? $current : (int) (is_numeric($current) ? $current : 0)) + $amount;
        $this->set($key, $next, $ttl);

        return $next;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function namespace(string $namespace): NamespacedCache
    {
        return new NamespacedCache($this, $namespace);
    }

    /**
     * Return entry count and estimated byte size for diagnostics.
     *
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        $now = ($this->clock)();
        $entries = 0;
        $bytes = 0;

        foreach ($this->items as $k => $entry) {
            if ($entry['expires_at'] !== null && $entry['expires_at'] <= $now) {
                unset($this->items[$k]);
                continue;
            }
            $entries++;
            $encoded = json_encode($entry['value']);
            $bytes += is_string($encoded) ? strlen($encoded) : 0;
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }
}
