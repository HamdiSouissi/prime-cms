<?php

declare(strict_types=1);

namespace Prime\Cache;

use Prime\Hooks\Hooks;

/**
 * Coordinates the active data cache driver (`file`, `apcu`, `array`) and the public page cache,
 * providing unified flush, stats, and targeted invalidation helpers.
 */
final class CacheManager
{
    public function __construct(
        private readonly CacheInterface $data,
        private readonly PageCache $pages,
        private readonly string $driverName = 'file',
        private readonly ?Hooks $hooks = null,
    ) {
    }

    public function data(): CacheInterface
    {
        return $this->data;
    }

    public function pages(): PageCache
    {
        return $this->pages;
    }

    public function driverName(): string
    {
        return $this->driverName;
    }

    public function namespace(string $namespace): NamespacedCache
    {
        return $this->data->namespace($namespace);
    }

    /**
     * Flush data cache only and emit `cache.purged`.
     */
    public function clearData(): bool
    {
        $ok = $this->data->flush();
        $this->hooks?->emit('cache.purged', ['scope' => 'data']);

        return $ok;
    }

    /**
     * Flush page cache only and emit `cache.purged`.
     */
    public function clearPages(): int
    {
        return $this->pages->purgeAll();
    }

    /**
     * Flush both data cache and page cache, emitting `cache.purged` (`scope: all`).
     */
    public function clearAll(): void
    {
        $this->data->flush();
        $pagesCleared = $this->pages->purgeAll();
        $this->hooks?->emit('cache.purged', ['scope' => 'all', 'pages' => $pagesCleared]);
    }

    /**
     * Remove expired entries from file-backed data & page caches.
     *
     * @return array{data: int, pages: int}
     */
    public function pruneExpired(): array
    {
        $dataPruned = $this->data instanceof FileCache ? $this->data->pruneExpired() : 0;
        $pagesPruned = $this->pages->pruneExpired();

        return ['data' => $dataPruned, 'pages' => $pagesPruned];
    }

    /**
     * Return combined statistics for the Performance admin page and System Health.
     *
     * @return array{
     *   driver: string,
     *   apcu_available: bool,
     *   page_enabled: bool,
     *   page_ttl: int,
     *   data: array{entries: int, bytes: int},
     *   pages: array{entries: int, bytes: int},
     *   total_entries: int,
     *   total_bytes: int
     * }
     */
    public function stats(): array
    {
        $dataStats = ['entries' => 0, 'bytes' => 0];
        if ($this->data instanceof FileCache || $this->data instanceof ApcuCache || $this->data instanceof ArrayCache) {
            $dataStats = $this->data->stats();
        }

        $pageStats = $this->pages->stats();

        return [
            'driver' => $this->driverName,
            'apcu_available' => ApcuCache::isAvailable(),
            'page_enabled' => $this->pages->isEnabled(),
            'page_ttl' => $this->pages->defaultTtl(),
            'data' => $dataStats,
            'pages' => $pageStats,
            'total_entries' => $dataStats['entries'] + $pageStats['entries'],
            'total_bytes' => $dataStats['bytes'] + $pageStats['bytes'],
        ];
    }
}
