<?php

declare(strict_types=1);

namespace Prime\Cache;

use Prime\Hooks\Hooks;
use Prime\Kernel\ServiceProvider;

/**
 * Registers the data cache (`CacheInterface`), the public page cache (`PageCache`), and the
 * `CacheManager` façade used by admin screens and CLI commands.
 *
 * The active driver, page-cache toggle, and TTL are mirrored into `storage/cache/config.json`
 * whenever the corresponding settings are saved, so that caches stay consistent across requests
 * without requiring a database round-trip at boot time.
 */
final class CacheServiceProvider extends ServiceProvider
{
    public const CONFIG_FILENAME = 'cache/config.json';

    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(CacheInterface::class, static function () use ($app): CacheInterface {
            $persisted = CacheRuntimeConfig::read($app->storagePath(self::CONFIG_FILENAME));
            $driver = strtolower(trim((string) ($persisted['driver']
                ?? $app->config()->get('cache.driver', 'file'))));

            if ($driver === 'array') {
                return new ArrayCache();
            }

            if ($driver === 'apcu' && ApcuCache::isAvailable()) {
                return new ApcuCache();
            }

            return new FileCache($app->storagePath('cache/data'));
        });

        $c->singleton(PageCache::class, static function () use ($app, $c): PageCache {
            $persisted = CacheRuntimeConfig::read($app->storagePath(self::CONFIG_FILENAME));

            $enabled = (bool) ($persisted['page_enabled'] ?? $app->config()->get('cache.page.enabled', true));
            $ttl = (int) ($persisted['page_ttl'] ?? $app->config()->get('cache.page.ttl', 600));
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            $directory = $app->config()->get('cache.page.directory');
            if (!is_string($directory) || trim($directory) === '') {
                $directory = $app->storagePath('cache/pages');
            }

            return new PageCache(
                directory: $directory,
                enabled: $enabled,
                defaultTtl: $ttl,
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });

        $c->singleton(CacheManager::class, static function () use ($c): CacheManager {
            /** @var CacheInterface $data */
            $data = $c->get(CacheInterface::class);
            /** @var PageCache $pages */
            $pages = $c->get(PageCache::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            $driverName = match (true) {
                $data instanceof ApcuCache => 'apcu',
                $data instanceof ArrayCache => 'array',
                default => 'file',
            };

            return new CacheManager(
                data: $data,
                pages: $pages,
                driverName: $driverName,
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });
    }

    public function boot(): void
    {
        $app = $this->app;
        $c = $app->container();

        if (!$c->has(CacheManager::class) || !$c->has(Hooks::class)) {
            return;
        }

        /** @var CacheManager $manager */
        $manager = $c->get(CacheManager::class);
        /** @var Hooks $hooks */
        $hooks = $c->get(Hooks::class);

        (new CachePurgeSubscriber($manager->pages(), $manager))->register($hooks);

        $hooks->on('settings.updated', static function (string $name, mixed $old, mixed $new) use ($manager, $app): void {
            $changed = CacheRuntimeConfig::applySetting($app->storagePath(self::CONFIG_FILENAME), $name, $new);

            if ($changed['page_enabled'] !== null) {
                $manager->pages()->setEnabled($changed['page_enabled']);
            }
            if ($changed['page_ttl'] !== null) {
                $manager->pages()->setDefaultTtl($changed['page_ttl']);
            }

            // Internal scheduler bookkeeping must not invalidate the whole cache.
            if (str_starts_with($name, 'scheduler.')) {
                return;
            }

            $manager->namespace('settings')->flush();
            $manager->namespace('sitemap')->flush();
            $manager->pages()->purgeAll();
        });
    }
}
