<?php

declare(strict_types=1);

namespace Prime\Jobs;

use Prime\Content\EntryService;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Settings\Settings;
use Throwable;

/**
 * Wires the queue: storage, handler registry, the runner used by `queue:work`, and the handlers core
 * and extensions contribute.
 */
final class JobsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $c = $this->app->container();

        $c->singleton(JobHandlerRegistry::class, static fn (Container $c): JobHandlerRegistry => new JobHandlerRegistry($c));

        $c->singleton(JobRepository::class, static function (Container $c): JobRepository {
            /** @var Connection $connection */
            $connection = $c->get(Connection::class);

            return new JobRepository($connection);
        });

        $c->singleton(ContentPublishHandler::class, static function (Container $c): ContentPublishHandler {
            /** @var EntryService $entries */
            $entries = $c->get(EntryService::class);

            return new ContentPublishHandler($entries);
        });

        $c->singleton(JobQueue::class, static function (Container $c): JobQueue {
            /** @var JobRepository $repository */
            $repository = $c->get(JobRepository::class);
            /** @var JobHandlerRegistry $registry */
            $registry = $c->get(JobHandlerRegistry::class);

            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;

            return new JobQueue(
                $repository,
                $registry,
                $hooks instanceof Hooks ? $hooks : null,
                $logger instanceof Logger ? $logger : null,
                self::queueTries($c),
            );
        });
    }

    public function boot(): void
    {
        $c = $this->app->container();
        /** @var JobHandlerRegistry $registry */
        $registry = $c->get(JobHandlerRegistry::class);

        $registry->register(ContentPublishHandler::NAME, ContentPublishHandler::class);

        $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
        if ($hooks instanceof Hooks) {
            // Extensions declare their handlers while the app boots, before the first worker tick.
            $hooks->on('app.booted', static function () use ($registry, $hooks): void {
                $hooks->apply('jobs.register_handlers', $registry);
            }, 8);
        }
    }

    /**
     * `scheduler.queue_tries` is a registry-backed setting; a site whose tables are not ready yet
     * keeps the documented default instead of failing at the first dispatch.
     */
    private static function queueTries(Container $c): int
    {
        try {
            if (!$c->has(Settings::class)) {
                return 3;
            }

            /** @var Settings $settings */
            $settings = $c->get(Settings::class);

            return $settings->getInt('scheduler.queue_tries', 3, 1, 10);
        } catch (Throwable) {
            return 3;
        }
    }
}
