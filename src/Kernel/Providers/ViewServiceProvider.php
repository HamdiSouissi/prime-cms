<?php

declare(strict_types=1);

namespace Prime\Kernel\Providers;

use Prime\Hooks\Hooks;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\View\View;

/**
 * Registers the pure-PHP template engine.
 */
final class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $app->container()->singleton(View::class, static function (Container $container) use ($app): View {
            $hooks = $container->has(Hooks::class) ? $container->get(Hooks::class) : null;

            return new View(
                $app->resourcesPath('views'),
                $hooks instanceof Hooks ? $hooks : null,
            );
        });
    }
}
