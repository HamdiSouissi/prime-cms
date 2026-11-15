<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Route;
use Prime\Http\Router;

/**
 * Router facade exposed via `PluginContext::router()` with ergonomic `/admin/{slug}`
 * and front-office route group helpers.
 */
final class PluginRouter
{
    public function __construct(
        private readonly Router $router,
        private readonly string $slug,
    ) {
    }

    /**
     * Register admin routes mounted under `/admin/{slug}` with `auth` middleware
     * (and optional capability middleware `can:{capability}`).
     *
     * @param callable(Router): void $callback
     * @param list<string|MiddlewareInterface> $extraMiddleware
     */
    public function adminGroup(callable $callback, ?string $capability = null, array $extraMiddleware = []): void
    {
        /** @var list<string|MiddlewareInterface> $middleware */
        $middleware = ['auth'];
        if ($capability !== null && $capability !== '') {
            $middleware[] = 'can:' . $capability;
        }
        foreach ($extraMiddleware as $mw) {
            $middleware[] = $mw;
        }

        $this->router->group([
            'prefix' => '/admin/' . $this->slug,
            'middleware' => $middleware,
            'name' => 'plugin.' . $this->slug . '.admin.',
        ], $callback);
    }

    /**
     * Register front-office routes with optional prefix and middleware.
     *
     * @param callable(Router): void $callback
     * @param list<string|MiddlewareInterface> $middleware
     */
    public function frontGroup(callable $callback, string $prefix = '', array $middleware = []): void
    {
        $this->router->group([
            'prefix' => $prefix,
            'middleware' => $middleware,
            'name' => 'plugin.' . $this->slug . '.',
        ], $callback);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function get(string $path, callable|array $handler): Route
    {
        return $this->router->get($path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function post(string $path, callable|array $handler): Route
    {
        return $this->router->post($path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->router->put($path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function patch(string $path, callable|array $handler): Route
    {
        return $this->router->patch($path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function delete(string $path, callable|array $handler): Route
    {
        return $this->router->delete($path, $handler);
    }

    /**
     * @param array{prefix?: string, middleware?: string|MiddlewareInterface|list<string|MiddlewareInterface>, name?: string} $attributes
     * @param callable(Router): void $callback
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->router->group($attributes, $callback);
    }

    public function core(): Router
    {
        return $this->router;
    }
}
