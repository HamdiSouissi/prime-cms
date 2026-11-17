<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Hooks\Hooks;
use Prime\Log\Logger;
use Throwable;

/**
 * Executes the due minutes of a schedule.
 *
 * Two files per task live in `storage/cache/scheduler`: `<name>.tick` holds the unix timestamp of
 * the last cron minute that was processed (which is why a stopped runner catches up instead of
 * skipping), and `<name>.lock` is held with `flock()` for the duration of the task so an overlapping
 * run — the docker service plus a visitor-triggered tick, say — is skipped rather than duplicated.
 */
final class SchedulerRunner
{
    public const TICK_FORMAT = 'Y-m-d H:i';

    public function __construct(
        private readonly Schedule $schedule,
        private readonly string $stampDirectory,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        private readonly mixed $commandRunner = null,
        private readonly int $lockSeconds = 3600,
    ) {
    }

    /**
     * Run every task that owes work. `$force` runs all of them, once, whatever the clock says — which
     * is what `schedule:run --force` and the docker `scheduler` service use.
     *
     * @return list<array{name: string, status: string, tick: ?string, duration_ms: int, error: ?string}>
     */
    public function run(?int $now = null, bool $force = false): array
    {
        $moment = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('UTC'));
        $moment = $moment->setTime((int) $moment->format('G'), (int) $moment->format('i'), 0);
        $results = [];

        foreach ($this->schedule->tasks() as $task) {
            $lastRun = $this->lastRun($task);
            $tick = $force ? $moment : $task->dueTick($moment, $lastRun);

            if ($tick === null) {
                continue;
            }

            $results[] = $this->runTask($task, $tick);
        }

        if ($results !== []) {
            $this->hooks?->emit('scheduler.ran', $results, $force);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function dueNames(?int $now = null): array
    {
        $moment = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('UTC'));
        $due = [];

        foreach ($this->schedule->tasks() as $task) {
            if ($task->dueTick($moment, $this->lastRun($task)) !== null) {
                $due[] = $task->name;
            }
        }

        return $due;
    }

    /**
     * Name → last processed tick, as `Y-m-d H:i` in UTC.
     *
     * @return array<string, string>
     */
    public function lastRuns(): array
    {
        $runs = [];

        foreach ($this->schedule->tasks() as $task) {
            $stamp = $this->lastRun($task);

            if ($stamp !== null) {
                $runs[$task->name] = $stamp->format(self::TICK_FORMAT);
            }
        }

        return $runs;
    }

    /**
     * Last error message per task, for the site-health screen.
     *
     * @return array<string, string>
     */
    public function lastErrors(): array
    {
        $errors = [];

        foreach ($this->schedule->tasks() as $task) {
            $file = $this->path($task->name . '.error');
            $message = is_readable($file) ? trim((string) @file_get_contents($file)) : '';

            if ($message !== '') {
                $errors[$task->name] = $message;
            }
        }

        return $errors;
    }

    /**
     * @return array{name: string, status: string, tick: ?string, duration_ms: int, error: ?string}
     */
    private function runTask(ScheduledTask $task, DateTimeImmutable $tick): array
    {
        $startedAt = microtime(true);
        $lock = $this->acquire($task);

        if ($lock === null) {
            return [
                'name' => $task->name,
                'status' => 'skipped',
                'tick' => $tick->format(self::TICK_FORMAT),
                'duration_ms' => 0,
                'error' => 'Exécution déjà en cours (verrou appelé sur ' . $task->name . ').',
            ];
        }

        $error = null;

        try {
            if ($task->isCommand()) {
                $runner = $this->commandRunner;

                if (!is_callable($runner)) {
                    throw new \RuntimeException('Aucun exécuteur de commandes console n’est disponible pour le planificateur.');
                }

                $runner($task->commandLine());
            } else {
                $closure = $task->closure();
                $closure?->__invoke($tick);
            }

            $this->hooks?->emit('scheduler.task.ran', $task->name, $tick->format(self::TICK_FORMAT));
            $this->clearError($task);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $this->writeError($task, $error);
            $this->logger?->error('Tâche planifiée en échec', [
                'task' => $task->name,
                'tick' => $tick->format(self::TICK_FORMAT),
                'error' => $error,
            ]);
        } finally {
            // The tick is recorded even when the task failed: a broken task must not be retried every
            // minute, the error is surfaced instead.
            $this->writeLastRun($task, $tick);
            $this->release($lock[0]);
        }

        return [
            'name' => $task->name,
            'status' => $error === null ? 'ran' : 'error',
            'tick' => $tick->format(self::TICK_FORMAT),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error,
        ];
    }

    private function lastRun(ScheduledTask $task): ?DateTimeImmutable
    {
        $file = $this->path($task->name . '.tick');
        $stamp = is_readable($file) ? trim((string) @file_get_contents($file)) : '';

        if ($stamp === '' || preg_match('/^\d{9,11}$/', $stamp) !== 1) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $stamp))->setTimezone(new DateTimeZone('UTC'));
    }

    private function writeLastRun(ScheduledTask $task, DateTimeImmutable $tick): void
    {
        $this->ensureDirectory();
        @file_put_contents($this->path($task->name . '.tick'), (string) $tick->getTimestamp(), LOCK_EX);
    }

    private function writeError(ScheduledTask $task, string $message): void
    {
        $this->ensureDirectory();
        @file_put_contents(
            $this->path($task->name . '.error'),
            sprintf('[%s] %s', date(self::TICK_FORMAT), $message),
            LOCK_EX,
        );
    }

    private function clearError(ScheduledTask $task): void
    {
        $file = $this->path($task->name . '.error');

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Take the per-task lock. `null` means somebody else holds it and this tick must be skipped;
     * a one-element array with a `null` handle means the task runs unprotected, either because it
     * opted out of `withoutOverlapping` or because the lock file cannot be created.
     *
     * @return array{0: resource|null}|null
     */
    private function acquire(ScheduledTask $task): ?array
    {
        if (!$task->withoutOverlapping) {
            return [null];
        }

        $this->ensureDirectory();
        $handle = @fopen($this->path($task->name . '.lock'), 'c');

        if ($handle === false) {
            return [null];
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        if ($this->lockSeconds > 0) {
            @ftruncate($handle, 0);
            @fwrite($handle, (string) time());
            @fflush($handle);
        }

        return [$handle];
    }

    /**
     * @param resource|null $lock
     */
    private function release($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->stampDirectory, '/') . '/' . $file;
    }

    private function ensureDirectory(): void
    {
        if ($this->stampDirectory === '') {
            return;
        }

        if (!is_dir($this->stampDirectory)) {
            @mkdir($this->stampDirectory, 0775, true);
        }
    }
}
