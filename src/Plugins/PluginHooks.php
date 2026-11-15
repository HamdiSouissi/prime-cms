<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Hooks\Hooks;

/**
 * Owner-attributed Hooks facade exposed via `PluginContext::hooks()`.
 *
 * Every listener or filter registered through this facade is permanently attributed
 * to `$pluginSlug` even when registered dynamically inside controllers or deferred callbacks.
 */
final class PluginHooks
{
    public function __construct(
        private readonly Hooks $hooks,
        private readonly string $pluginSlug,
    ) {
    }

    public function on(string $event, callable $listener, int $priority = 10): void
    {
        $this->hooks->on($event, $listener, $priority, $this->pluginSlug);
    }

    public function emit(string $event, mixed ...$args): void
    {
        $this->hooks->emit($event, ...$args);
    }

    public function filter(string $name, callable $callback, int $priority = 10): void
    {
        $this->hooks->filter($name, $callback, $priority, $this->pluginSlug);
    }

    public function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        return $this->hooks->apply($name, $value, ...$args);
    }

    public function off(string $name, ?callable $callback = null): void
    {
        $this->hooks->off($name, $callback);
    }

    public function has(string $name): bool
    {
        return $this->hooks->has($name);
    }

    public function fired(string $event): int
    {
        return $this->hooks->fired($event);
    }

    public function current(): ?string
    {
        return $this->hooks->current();
    }
}
