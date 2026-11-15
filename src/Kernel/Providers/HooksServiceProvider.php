<?php

declare(strict_types=1);

namespace Prime\Kernel\Providers;

use Prime\Hooks\Hooks;
use Prime\Kernel\ServiceProvider;

/**
 * Registers the core Hooks engine into the container.
 */
final class HooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(Hooks::class, static fn (): Hooks => new Hooks());
    }
}
