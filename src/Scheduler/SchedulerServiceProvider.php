<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use Closure;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Content\EntryService;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Middleware\PassthroughMiddleware;
use Prime\Http\Middleware\RunSchedulerIfDue;
use Prime\Install\InstallCheckerInterface;
use Prime\Jobs\JobRepository;
use Prime\Kernel\Application;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Mail\MailLogRepository;
use Prime\Settings\Settings;
use RuntimeException;
use Throwable;

/**
 * Registers the schedule, the runner used by `schedule:run` and by the docker `scheduler` service, the
 * visitor-time fallback, and the core tasks every installation needs.
 */
final class SchedulerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(Schedule::class, static fn (): Schedule => new Schedule());

        $c->singleton(SchedulerRunner::class, static function (Container $c) use ($app): SchedulerRunner {
            /** @var Schedule $schedule */
            $schedule = $c->get(Schedule::class);

            return new SchedulerRunner(
                $schedule,
                $app->storagePath('cache/scheduler'),
                self::hooks($c),
                self::logger($c),
                self::commandRunner($app),
            );
        });

        $c->singleton(SchedulerFallback::class, static function (Container $c) use ($app): SchedulerFallback {
            /** @var SchedulerRunner $runner */
            $runner = $c->get(SchedulerRunner::class);
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;

            return new SchedulerFallback(
                $runner,
                $app->storagePath('cache/scheduler/fallback.tick'),
                $settings instanceof Settings ? $settings : null,
                self::logger($c),
            );
        });
    }

    public function boot(): void
    {
        $c = $this->app->container();
        /** @var Schedule $schedule */
        $schedule = $c->get(Schedule::class);

        $this->registerCoreTasks($schedule, $c);

        $hooks = self::hooks($c);

        if ($hooks !== null) {
            // Extensions add their tasks while the app boots; the catalogue is frozen afterwards.
            $hooks->on('app.booted', static function () use ($schedule, $hooks): void {
                $hooks->apply('scheduler.register', $schedule);
                $schedule->lock();
            }, 6);
        } else {
            $schedule->lock();
        }

        $this->registerMiddlewareAlias($c);
    }

    private function registerCoreTasks(Schedule $schedule, Container $c): void
    {
        if ($schedule->isLocked()) {
            return;
        }

        if ($c->has(EntryService::class)) {
            $schedule->command(
                'content.publish_scheduled',
                'content:publish-scheduled',
                '* * * * *',
                'Publie les contenus arrivés à échéance.',
            );

            $schedule->call(
                'content.purge_trash',
                static function () use ($c): void {
                    /** @var EntryService $entries */
                    $entries = $c->get(EntryService::class);
                    $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
                    $entries->purgeTrash($settings instanceof Settings ? $settings->getInt('writing.trash_days', 30, 1, 365) : 30);
                },
                '30 3 * * *',
                'Vide la corbeille selon la durée de rétention.',
            );
        }

        if ($c->has(JobRepository::class)) {
            $schedule->call(
                'jobs.prune',
                static function () use ($c): void {
                    /** @var JobRepository $jobs */
                    $jobs = $c->get(JobRepository::class);
                    $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
                    $ttl = $settings instanceof Settings
                        ? $settings->getInt('scheduler.queue_ttl_minutes', 60, 5, 10080)
                        : 60;

                    // Stuck reservations first, then the failed jobs nobody will retry any more.
                    $jobs->releaseStuck(600);
                    $jobs->pruneFailed($ttl);
                },
                '15 * * * *',
                'Récupère les réservations expirées et purge les échecs anciens.',
            );
        }

        if ($c->has(MailLogRepository::class)) {
            // `mail.log_days` is a promise the settings screen makes; a promise nobody keeps is a
            // table that grows for ever, so the retention is applied here rather than by hand.
            $schedule->call(
                'mail.prune_log',
                static function () use ($c): void {
                    /** @var MailLogRepository $logs */
                    $logs = $c->get(MailLogRepository::class);
                    $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;
                    $days = $settings instanceof Settings
                        ? $settings->getInt('mail.log_days', 30, 1, 365)
                        : 30;

                    $logs->prune($days);
                },
                '45 3 * * *',
                'Applique la rétention du journal des e-mails.',
            );
        }
    }

    /**
     * Command lines are executed in-process: `exec()` is disabled on many shared hosts, and a
     * non-zero exit code has to reach the runner so the tick is recorded as an error.
     */
    private static function commandRunner(Application $app): Closure
    {
        return static function (string $commandLine) use ($app): void {
            $argv = ['prime', ...preg_split('/\s+/', trim($commandLine)) ?: []];

            // Buffered on purpose: a tick can happen inside a visitor request (the fallback), where
            // everything the command prints would land in the page instead of the log.
            $output = new ConsoleOutput(true);
            $exit = (new ConsoleApplication($app))->run($argv, $output);

            if ($exit !== 0) {
                $log = trim($output->stderr() . ' ' . $output->stdout());

                throw new RuntimeException(rtrim(sprintf(
                    'La commande « %s » a échoué (code %d). %s',
                    $commandLine,
                    $exit,
                    $log === '' ? '' : substr($log, -400),
                )));
            }
        };
    }

    private static function hooks(Container $c): ?Hooks
    {
        $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

        return $hooks instanceof Hooks ? $hooks : null;
    }

    private static function logger(Container $c): ?Logger
    {
        $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;

        return $logger instanceof Logger ? $logger : null;
    }

    private function registerMiddlewareAlias(Container $c): void
    {
        $kernel = $c->has(Kernel::class) ? $c->get(Kernel::class) : null;

        if (!$kernel instanceof Kernel) {
            return;
        }

        $container = $c;

        $kernel->registerMiddlewareAlias('scheduler', static function () use ($container): MiddlewareInterface {
            try {
                // Before installation there is no table to read the schedule from, and a
                // connection attempt must never be made on the back of a visitor request.
                $checker = $container->has(InstallCheckerInterface::class)
                    ? $container->get(InstallCheckerInterface::class)
                    : null;

                if (!$checker instanceof InstallCheckerInterface || !$checker->isInstalled()) {
                    return new PassthroughMiddleware();
                }

                if (!$container->has(SchedulerFallback::class)) {
                    return new PassthroughMiddleware();
                }

                $fallback = $container->get(SchedulerFallback::class);

                return $fallback instanceof SchedulerFallback && $fallback->enabled()
                    ? new RunSchedulerIfDue($fallback)
                    : new PassthroughMiddleware();
            } catch (Throwable) {
                return new PassthroughMiddleware();
            }
        });
    }
}
