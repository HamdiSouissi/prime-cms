<?php

declare(strict_types=1);

namespace Prime\Kernel\Providers;

use Prime\Hooks\Hooks;
use Prime\Http\Controller\HomeController;
use Prime\Http\Kernel;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Router;
use Prime\Kernel\Config;
use Prime\Kernel\Container;
use Prime\Kernel\ErrorHandler;
use Prime\Kernel\ServiceProvider;

/**
 * Registers the HTTP router, HTTP kernel, and core Phase 1 routes.
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();

        $container->singleton(Router::class, static fn (): Router => new Router());

        $container->singleton(Kernel::class, static function (Container $c): Kernel {
            /** @var Router $router */
            $router = $c->get(Router::class);
            /** @var Hooks $hooks */
            $hooks = $c->get(Hooks::class);
            /** @var ErrorHandler $errorHandler */
            $errorHandler = $c->get(ErrorHandler::class);
            /** @var Config $config */
            $config = $c->get(Config::class);

            $rawMiddlewares = $config->get('app.middleware', []);
            /** @var list<string|MiddlewareInterface> $middlewares */
            $middlewares = is_array($rawMiddlewares) ? array_values($rawMiddlewares) : [];

            return new Kernel($router, $c, $hooks, $errorHandler, $middlewares);
        });
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->get(Router::class);
        /** @var Hooks $hooks */
        $hooks = $this->app->get(Hooks::class);

        $router->get('/', [HomeController::class, 'index'])->name('home');
        $router->get('/health', [HomeController::class, 'health'])->name('health');

        $hooks->emit('routes.register', $router);
    }
}
