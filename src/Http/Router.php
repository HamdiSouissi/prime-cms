<?php

declare(strict_types=1);

namespace Prime\Http;

use InvalidArgumentException;
use Prime\Http\Exception\MethodNotAllowedException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Middleware\MiddlewareInterface;

/**
 * HTTP router supporting parameters, regex constraints, optional segments,
 * named routes, route groups, and automatic 404 / 405 responses.
 */
final class Router
{
    private const ALL_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @var list<Route>
     */
    private array $routes = [];

    /**
     * Active group stack for nested prefixes, middlewares, and name prefixes.
     *
     * @var list<array{prefix: string, middleware: list<string|MiddlewareInterface>, name: string}>
     */
    private array $groupStack = [];

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function get(string $path, callable|array $handler): Route
    {
        return $this->match(['GET'], $path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function post(string $path, callable|array $handler): Route
    {
        return $this->match(['POST'], $path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->match(['PUT'], $path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function patch(string $path, callable|array $handler): Route
    {
        return $this->match(['PATCH'], $path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function delete(string $path, callable|array $handler): Route
    {
        return $this->match(['DELETE'], $path, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function any(string $path, callable|array $handler): Route
    {
        return $this->match(self::ALL_METHODS, $path, $handler);
    }

    /**
     * Register a route matching one or more HTTP methods.
     *
     * @param list<string> $methods
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function match(array $methods, string $path, callable|array $handler): Route
    {
        $fullPath = $this->applyGroupPrefix($path);
        $middlewares = $this->collectGroupMiddlewares();
        $namePrefix = $this->collectGroupNamePrefix();

        $route = new Route(
            methods: $methods,
            path: $fullPath,
            handler: $handler,
            middleware: $middlewares,
            namePrefix: $namePrefix,
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Group routes under shared prefix and/or middleware attributes.
     *
     * @param array{prefix?: string, middleware?: string|MiddlewareInterface|list<string|MiddlewareInterface>, name?: string} $attributes
     * @param callable(Router): void $callback
     */
    public function group(array $attributes, callable $callback): void
    {
        $prefix = isset($attributes['prefix']) ? '/' . trim($attributes['prefix'], '/') : '';
        if ($prefix === '/') {
            $prefix = '';
        }

        $middleware = [];
        if (isset($attributes['middleware'])) {
            $middleware = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];
        }

        $name = $attributes['name'] ?? '';

        $this->groupStack[] = [
            'prefix' => $prefix,
            'middleware' => $middleware,
            'name' => $name,
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Match an incoming request against registered routes.
     *
     * @return array{route: Route, params: array<string, string>}
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     */
    public function dispatch(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $route->matchesPath($path);
            if ($params === null) {
                continue;
            }

            if ($route->matchesMethod($method)) {
                return [
                    'route' => $route,
                    'params' => $params,
                ];
            }

            foreach ($route->methods() as $allowed) {
                $allowedMethods[] = $allowed;
            }
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowedMethods)));
        }

        throw new NotFoundException(sprintf('Aucune route ne correspond au chemin "%s".', $path));
    }

    /**
     * Generate a URL path for a named route.
     *
     * @param array<string, scalar|null> $params
     */
    public function url(string $name, array $params = []): string
    {
        $route = $this->findByName($name);
        if ($route === null) {
            throw new InvalidArgumentException(sprintf('Named route [%s] does not exist.', $name));
        }

        return $route->generateUrl($params);
    }

    /**
     * Determine whether a named route is registered.
     */
    public function hasRoute(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    /**
     * Return all registered routes in registration order.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function findByName(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    private function applyGroupPrefix(string $path): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
        }

        $trimmedPath = trim($path, '/');

        if ($prefix === '' && $trimmedPath === '') {
            return '/';
        }

        if ($trimmedPath === '') {
            return $prefix;
        }

        return $prefix . '/' . $trimmedPath;
    }

    /**
     * @return list<string|MiddlewareInterface>
     */
    private function collectGroupMiddlewares(): array
    {
        $middlewares = [];
        foreach ($this->groupStack as $group) {
            foreach ($group['middleware'] as $middleware) {
                $middlewares[] = $middleware;
            }
        }

        return $middlewares;
    }

    private function collectGroupNamePrefix(): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['name'];
        }

        return $prefix;
    }
}
