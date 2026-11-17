<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One entry of the schedule: a name, a cron expression, and what to run — either a closure the
 * service provider built from the container, or a console command line handed to the command runner.
 */
final class ScheduledTask
{
    public readonly CronExpression $cron;

    public function __construct(
        public readonly string $name,
        string $expression,
        public readonly Closure|string $action,
        public readonly ?string $description = null,
        public readonly bool $withoutOverlapping = true,
        public readonly string $owner = 'core',
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Nom de tâche planifiée invalide : « %s ».', $name));
        }

        $this->cron = CronExpression::parse($expression);
    }

    public function expression(): string
    {
        return $this->cron->expression();
    }

    public function isCommand(): bool
    {
        return is_string($this->action);
    }

    public function commandLine(): string
    {
        return is_string($this->action) ? $this->action : '';
    }

    public function closure(): ?Closure
    {
        return $this->action instanceof Closure ? $this->action : null;
    }

    /**
     * The cron minute this task owes work for, or `null` when it is up to date.
     *
     * Comparing ticks (instead of "is the current minute a match") is what makes a missed window
     * recoverable: if the runner was down from 03:00 to 05:10, the daily 03:00 task still runs once.
     */
    public function dueTick(DateTimeImmutable $now, ?DateTimeImmutable $lastRun = null): ?DateTimeImmutable
    {
        $tick = $this->cron->previousRun($now);

        if ($tick === null) {
            return null;
        }

        return $lastRun !== null && $tick <= $lastRun ? null : $tick;
    }

    public function nextRun(?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        return $this->cron->nextRun($from);
    }
}
