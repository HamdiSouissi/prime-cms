<?php

declare(strict_types=1);

namespace Prime\Hooks;

use Closure;
use Prime\Hooks\Exception\InvalidHookNameException;
use Throwable;

/**
 * Event and filter hooks engine — the extensibility backbone of Prime CMS.
 *
 * Supports optional owner attribution (`withOwner()`) so listeners and filters registered
 * by plugins are isolated: any Throwable thrown inside a plugin listener is caught,
 * attributed to its owning plugin via the registered owner error handler, and never
 * interrupts core or other plugins' listeners. Core listeners (`owner === null`) keep
 * strict exception propagation.
 */
final class Hooks
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * Event listeners indexed by hook name.
     *
     * @var array<string, list<array{priority: int, seq: int, callback: callable, owner: ?string}>>
     */
    private array $listeners = [];

    /**
     * Filter callbacks indexed by hook name.
     *
     * @var array<string, list<array{priority: int, seq: int, callback: callable, owner: ?string}>>
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

    /**
     * Stack of active plugin owner slugs while registering callbacks.
     *
     * @var list<string>
     */
    private array $ownerStack = [];

    /**
     * Handler invoked when an owned (plugin) listener or filter throws a Throwable.
     *
     * @var (Closure(string, string, Throwable): void)|null
     */
    private ?Closure $ownerErrorHandler = null;

    private int $sequence = 0;

    /**
     * Execute a callable with an active plugin owner attribution context so any
     * `on()` or `filter()` calls made inside are tagged with `$owner`.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withOwner(string $owner, callable $callback): mixed
    {
        $this->ownerStack[] = $owner;

        try {
            return $callback();
        } finally {
            array_pop($this->ownerStack);
        }
    }

    /**
     * Return the current registration owner slug, or null for core registrations.
     */
    public function currentOwner(): ?string
    {
        if ($this->ownerStack === []) {
            return null;
        }

        return $this->ownerStack[array_key_last($this->ownerStack)];
    }

    /**
     * Set the isolation handler invoked whenever an owned plugin hook throws.
     *
     * @param (callable(string, string, Throwable): void)|null $handler
     */
    public function setOwnerErrorHandler(?callable $handler): void
    {
        $this->ownerErrorHandler = $handler !== null ? Closure::fromCallable($handler) : null;
    }

    /**
     * Register an event listener.
     */
    public function on(string $event, callable $listener, int $priority = 10, ?string $owner = null): void
    {
        $this->assertValidName($event);

        $this->listeners[$event][] = [
            'priority' => $priority,
            'seq' => $this->sequence++,
            'callback' => $listener,
            'owner' => $owner ?? $this->currentOwner(),
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
                $owner = $entry['owner'];
                if ($owner === null) {
                    ($entry['callback'])(...$args);
                    continue;
                }

                try {
                    ($entry['callback'])(...$args);
                } catch (Throwable $exception) {
                    $this->handleOwnedFailure($owner, $event, $exception);
                }
            }
        } finally {
            array_pop($this->executionStack);
        }
    }

    /**
     * Register a filter callback.
     */
    public function filter(string $name, callable $callback, int $priority = 10, ?string $owner = null): void
    {
        $this->assertValidName($name);

        $this->filters[$name][] = [
            'priority' => $priority,
            'seq' => $this->sequence++,
            'callback' => $callback,
            'owner' => $owner ?? $this->currentOwner(),
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
                $owner = $entry['owner'];
                if ($owner === null) {
                    $value = ($entry['callback'])($value, ...$args);
                    continue;
                }

                $previousValue = $value;
                try {
                    $value = ($entry['callback'])($value, ...$args);
                } catch (Throwable $exception) {
                    $value = $previousValue;
                    $this->handleOwnedFailure($owner, $name, $exception);
                }
            }
        } finally {
            array_pop($this->executionStack);
        }

        return $value;
    }

    /**
     * Apply all registered filters sequentially while validating/normalizing each filter's
     * return value immediately with plugin-owner attribution.
     *
     * The `$validator` receives `($candidateValue, $previousValue, $owner, $hookName)` and
     * must return the normalized safe value to pass to the next filter in the chain.
     *
     * @param callable(mixed, mixed, ?string, string): mixed $validator
     */
    public function applyValidated(string $name, mixed $value, callable $validator, mixed ...$args): mixed
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
                $owner = $entry['owner'];
                $previousValue = $value;

                if ($owner === null) {
                    $candidate = ($entry['callback'])($value, ...$args);
                } else {
                    try {
                        $candidate = ($entry['callback'])($value, ...$args);
                    } catch (Throwable $exception) {
                        $value = $previousValue;
                        $this->handleOwnedFailure($owner, $name, $exception);
                        continue;
                    }
                }

                $value = $validator($candidate, $previousValue, $owner, $name);
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
     * Remove every event listener and filter attributed to a specific plugin owner.
     */
    public function removeByOwner(string $owner): int
    {
        $removed = 0;

        foreach ($this->listeners as $name => $entries) {
            $kept = [];
            foreach ($entries as $entry) {
                if ($entry['owner'] === $owner) {
                    $removed++;
                } else {
                    $kept[] = $entry;
                }
            }
            if ($kept === []) {
                unset($this->listeners[$name]);
            } else {
                $this->listeners[$name] = $kept;
            }
        }

        foreach ($this->filters as $name => $entries) {
            $kept = [];
            foreach ($entries as $entry) {
                if ($entry['owner'] === $owner) {
                    $removed++;
                } else {
                    $kept[] = $entry;
                }
            }
            if ($kept === []) {
                unset($this->filters[$name]);
            } else {
                $this->filters[$name] = $kept;
            }
        }

        return $removed;
    }

    /**
     * Inspect registered listener/filter owners for a hook name (diagnostics & tests).
     *
     * @return list<array{type: 'event'|'filter', priority: int, owner: ?string}>
     */
    public function inspect(string $name): array
    {
        $this->assertValidName($name);
        $result = [];

        foreach ($this->listeners[$name] ?? [] as $entry) {
            $result[] = [
                'type' => 'event',
                'priority' => $entry['priority'],
                'owner' => $entry['owner'],
            ];
        }

        foreach ($this->filters[$name] ?? [] as $entry) {
            $result[] = [
                'type' => 'filter',
                'priority' => $entry['priority'],
                'owner' => $entry['owner'],
            ];
        }

        return $result;
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

    private function handleOwnedFailure(string $owner, string $hookName, Throwable $exception): void
    {
        // Detach the faulty plugin's remaining callbacks immediately to avoid cascading errors.
        $this->removeByOwner($owner);

        if ($this->ownerErrorHandler !== null) {
            ($this->ownerErrorHandler)($owner, $hookName, $exception);
        }
    }

    /**
     * Sort hook entries by priority (lowest first), preserving insertion order on ties.
     *
     * @param list<array{priority: int, seq: int, callback: callable, owner: ?string}> $entries
     * @return list<array{priority: int, seq: int, callback: callable, owner: ?string}>
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
