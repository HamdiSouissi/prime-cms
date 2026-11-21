<?php

declare(strict_types=1);

namespace Prime\Plugins;

use LogicException;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Route;
use Prime\Http\Router;
use Prime\Site\ReservedSlugValidator;

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
        $before = count($this->router->routes());

        $this->router->group([
            'prefix' => $prefix,
            'middleware' => $middleware,
            'name' => 'plugin.' . $this->slug . '.',
        ], $callback);

        $this->assertFreeFrontRoutes(array_slice($this->router->routes(), $before), array_slice($this->router->routes(), 0, $before));
    }

    /**
     * A public plugin route may not claim a core prefix (`/admin`, `/api`, `/uploads`…) nor answer
     * a method and path someone already answers: both are refused at boot with a clear message,
     * instead of silently shadowing a page.
     *
     * @param list<Route> $added
     * @param list<Route> $existing
     */
    private function assertFreeFrontRoutes(array $added, array $existing): void
    {
        $taken = [];
        foreach ($existing as $route) {
            foreach ($route->methods() as $method) {
                $taken[$method . ' ' . self::shape($route->path())] = true;
            }
        }

        foreach ($added as $route) {
            $first = strtolower(explode('/', trim($route->path(), '/'))[0]);
            if ($first !== '' && in_array($first, ReservedSlugValidator::SYSTEM_RESERVED, true)) {
                throw new LogicException(sprintf('Plugin "%s" cannot declare the public route %s: "/%s" is reserved by the core.', $this->slug, $route->path(), $first));
            }

            foreach ($route->methods() as $method) {
                if (isset($taken[$method . ' ' . self::shape($route->path())])) {
                    throw new LogicException(sprintf('Plugin "%s" declares %s %s, which is already answered by another route.', $this->slug, $method, $route->path()));
                }
            }
        }
    }

    private static function shape(string $path): string
    {
        return '/' . trim((string) preg_replace('/\{[^}]+\}/', '{}', $path), '/');
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
