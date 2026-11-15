<?php

declare(strict_types=1);

namespace Prime\Hooks;

use Prime\Hooks\Exception\InvalidHookNameException;

/**
 * Event and filter hooks engine — the extensibility backbone of Prime CMS.
 */
final class Hooks
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * Event listeners indexed by hook name.
     *
     * @var array<string, list<array{priority: int, seq: int, callback: callable}>>
     */
    private array $listeners = [];

    /**
     * Filter callbacks indexed by hook name.
     *
     * @var array<string, list<array{priority: int, seq: int, callback: callable}>>
     */
    private array $filters = [];

    /**
     * Number of times each hook has been executed.
     *
     * @var array<string, int>
     */
    private array $firedCounts = [];

    /**
     * Stack of currently executing hook names (supports nested hooks).
     *
     * @var list<string>
     */
    private array $executionStack = [];

    private int $sequence = 0;

    /**
     * Register an event listener.
     */
    public function on(string $event, callable $listener, int $priority = 10): void
    {
        $this->assertValidName($event);

        $this->listeners[$event][] = [
            'priority' => $priority,
            'seq' => $this->sequence++,
            'callback' => $listener,
        ];
    }

    /**
     * Emit an event and invoke all registered listeners in priority order.
     */
    public function emit(string $event, mixed ...$args): void
    {
        $this->assertValidName($event);

        $this->firedCounts[$event] = ($this->firedCounts[$event] ?? 0) + 1;

        if (!isset($this->listeners[$event]) || $this->listeners[$event] === []) {
            return;
        }

        // Take a sorted snapshot so listeners adding/removing hooks during execution never break iteration.
        $snapshot = $this->sortedSnapshot($this->listeners[$event]);

        $this->executionStack[] = $event;

        try {
            foreach ($snapshot as $entry) {
                ($entry['callback'])(...$args);
            }
        } finally {
            array_pop($this->executionStack);
        }
    }

    /**
     * Register a filter callback.
     */
    public function filter(string $name, callable $callback, int $priority = 10): void
    {
        $this->assertValidName($name);

        $this->filters[$name][] = [
            'priority' => $priority,
            'seq' => $this->sequence++,
            'callback' => $callback,
        ];
    }

    /**
     * Apply all registered filters sequentially to a value.
     */
    public function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        $this->assertValidName($name);

        $this->firedCounts[$name] = ($this->firedCounts[$name] ?? 0) + 1;

        if (!isset($this->filters[$name]) || $this->filters[$name] === []) {
            return $value;
        }

        $snapshot = $this->sortedSnapshot($this->filters[$name]);

        $this->executionStack[] = $name;

        try {
            foreach ($snapshot as $entry) {
                $value = ($entry['callback'])($value, ...$args);
            }
        } finally {
            array_pop($this->executionStack);
        }

        return $value;
    }

    /**
     * Remove one callback or all callbacks registered under a hook name.
     */
    public function off(string $name, ?callable $callback = null): void
    {
        $this->assertValidName($name);

        if ($callback === null) {
            unset($this->listeners[$name], $this->filters[$name]);

            return;
        }

        if (isset($this->listeners[$name])) {
            $this->listeners[$name] = array_values(array_filter(
                $this->listeners[$name],
                static fn (array $entry): bool => $entry['callback'] !== $callback,
            ));

            if ($this->listeners[$name] === []) {
                unset($this->listeners[$name]);
            }
        }

        if (isset($this->filters[$name])) {
            $this->filters[$name] = array_values(array_filter(
                $this->filters[$name],
                static fn (array $entry): bool => $entry['callback'] !== $callback,
            ));

            if ($this->filters[$name] === []) {
                unset($this->filters[$name]);
            }
        }
    }

    /**
     * Determine whether any listener or filter is registered for a hook name.
     */
    public function has(string $name): bool
    {
        $this->assertValidName($name);

        return (isset($this->listeners[$name]) && $this->listeners[$name] !== [])
            || (isset($this->filters[$name]) && $this->filters[$name] !== []);
    }

    /**
     * Return how many times a hook has been emitted or applied.
     */
    public function fired(string $event): int
    {
        $this->assertValidName($event);

        return $this->firedCounts[$event] ?? 0;
    }

    /**
     * Return the name of the currently executing hook, or null if none.
     */
    public function current(): ?string
    {
        if ($this->executionStack === []) {
            return null;
        }

        return $this->executionStack[array_key_last($this->executionStack)];
    }

    /**
     * Sort hook entries by priority (lowest first), preserving insertion order on ties.
     *
     * @param list<array{priority: int, seq: int, callback: callable}> $entries
     * @return list<array{priority: int, seq: int, callback: callable}>
     */
    private function sortedSnapshot(array $entries): array
    {
        usort(
            $entries,
            static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: ($a['seq'] <=> $b['seq']),
        );

        return $entries;
    }

    /**
     * Validate that a hook name uses lowercase dot-notation (`domain.action`).
     */
    private function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidHookNameException(
                sprintf('Invalid hook name [%s]. Hook names must use lowercase dot notation (e.g. "app.booted").', $name),
            );
        }
    }
}
