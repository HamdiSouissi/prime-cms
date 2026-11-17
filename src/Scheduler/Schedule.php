<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * The catalogue of scheduled tasks. Core fills it from `config/*.php` and its own providers; plugins
 * and themes add entries through the `scheduler.register` filter, which is applied once per request
 * before the catalogue is locked.
 */
final class Schedule
{
    /**
     * @var array<string, ScheduledTask>
     */
    private array $tasks = [];

    /**
     * @var array<string, list<string>>
     */
    private array $ownerIndex = [];

    private bool $locked = false;

    public function call(
        string $name,
        Closure $action,
        string $expression,
        ?string $description = null,
        string $owner = 'core',
        bool $withoutOverlapping = true,
    ): ScheduledTask {
        return $this->add(new ScheduledTask(
            name: $name,
            expression: $expression,
            action: $action,
            description: $description,
            withoutOverlapping: $withoutOverlapping,
            owner: $owner,
        ));
    }

    /**
     * Schedule a console command line (`content:publish-scheduled`), which is what the docker
     * `scheduler` service and `schedule:run --force` both end up executing.
     */
    public function command(
        string $name,
        string $commandLine,
        string $expression,
        ?string $description = null,
        string $owner = 'core',
        bool $withoutOverlapping = true,
    ): ScheduledTask {
        $commandLine = trim($commandLine);

        if ($commandLine === '') {
            throw new InvalidArgumentException('Une tâche planifiée doit recevoir une ligne de commande.');
        }

        return $this->add(new ScheduledTask(
            name: $name,
            expression: $expression,
            action: $commandLine,
            description: $description,
            withoutOverlapping: $withoutOverlapping,
            owner: $owner,
        ));
    }

    public function add(ScheduledTask $task): ScheduledTask
    {
        $this->assertMutable();

        if (isset($this->tasks[$task->name])) {
            throw new InvalidArgumentException(sprintf('La tâche planifiée « %s » est déjà déclarée.', $task->name));
        }

        $this->tasks[$task->name] = $task;
        $this->ownerIndex[$task->owner][] = $task->name;

        return $task;
    }

    public function remove(string $name): void
    {
        $this->assertMutable();
        $task = $this->tasks[$name] ?? null;

        if ($task === null) {
            return;
        }

        unset($this->tasks[$name]);
        $this->ownerIndex[$task->owner] = array_values(array_filter(
            $this->ownerIndex[$task->owner],
            static fn (string $candidate): bool => $candidate !== $name,
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->tasks[$name]);
    }

    public function get(string $name): ScheduledTask
    {
        $task = $this->tasks[$name] ?? null;

        if ($task === null) {
            throw new InvalidArgumentException(sprintf('Tâche planifiée inconnue : « %s ».', $name));
        }

        return $task;
    }

    /**
     * @return list<ScheduledTask>
     */
    public function tasks(?string $owner = null): array
    {
        $tasks = $this->tasks;
        ksort($tasks);

        if ($owner === null || $owner === '') {
            return array_values($tasks);
        }

        return array_values(array_filter(
            $tasks,
            static fn (ScheduledTask $task): bool => $task->owner === $owner,
        ));
    }

    /**
     * Drop everything a plugin contributed (used when a plugin is deactivated at runtime).
     */
    public function forgetOwner(string $owner): void
    {
        $this->assertMutable();

        foreach ($this->ownerIndex[$owner] ?? [] as $name) {
            unset($this->tasks[$name]);
        }

        unset($this->ownerIndex[$owner]);
    }

    /**
     * @param array<string, string> $lastRuns Last tick per task name, as kept by `SchedulerRunner`
     *
     * @return list<string>
     */
    public function dueNames(?DateTimeImmutable $now = null, array $lastRuns = []): array
    {
        $now ??= new DateTimeImmutable('now');
        $due = [];

        foreach ($this->tasks() as $task) {
            $lastRun = isset($lastRuns[$task->name]) ? self::timestamp($lastRuns[$task->name]) : null;

            if ($task->dueTick($now, $lastRun) !== null) {
                $due[] = $task->name;
            }
        }

        return $due;
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Test helper: open the catalogue again after a `lock()`.
     */
    public function unlock(): void
    {
        $this->locked = false;
    }

    private function assertMutable(): void
    {
        if ($this->locked) {
            throw new RuntimeException('Le planificateur est verrouillé : les tâches ne peuvent plus être modifiées.');
        }
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            return new DateTimeImmutable('@' . (int) $value);
        }

        return null;
    }
}
