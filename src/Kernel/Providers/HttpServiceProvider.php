<?php

declare(strict_types=1);

namespace Prime\Kernel\Providers;

use Prime\Hooks\Hooks;
use Prime\Http\Controller\HomeController;
use Prime\Http\Kernel;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Kernel\Config;
use Prime\Kernel\Container;
use Prime\Kernel\ErrorHandler;
use Prime\Kernel\ServiceProvider;
use Prime\Security\Csrf;

/**
 * Registers the HTTP router, HTTP kernel, Session, Csrf, and core routes.
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $app = $this->app;

        $container->singleton(Router::class, static fn (): Router => new Router());

        $container->singleton(Session::class, static fn (): Session => new Session(
            savePath: $app->storagePath('sessions'),
        ));

        $container->singleton(Csrf::class, static function (Container $c): Csrf {
            /** @var Session $session */
            $session = $c->get(Session::class);

            return new Csrf($session);
        });

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

            $rawRouteMiddlewares = $config->get('app.route_middleware', []);
            /** @var list<string|MiddlewareInterface> $routeMiddlewares */
            $routeMiddlewares = is_array($rawRouteMiddlewares) ? array_values($rawRouteMiddlewares) : [];

            return new Kernel($router, $c, $hooks, $errorHandler, $middlewares, $routeMiddlewares);
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
